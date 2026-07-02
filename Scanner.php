<?php
/**
 * Scanner.php — HTTP client + breadth-first crawler + scan orchestration.
 *
 * Uses cURL server-side (no browser CORS limits). Emits progress via an
 * optional callback so the API layer can stream Server-Sent Events.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Catalog.php';
require_once __DIR__ . '/Checks.php';

class Scanner
{
    private string $startUrl;
    private string $rootHost;
    private int $maxPages;
    private int $timeout;
    private $progress;              // callable|null
    private array $catFilter;       // enabled catalog groups (empty = all)

    private const UA = 'WebErrorScanner/1.0 (+PHP crawler)';
    private const MAX_LINK_CHECKS = 200;
    private const PROBE_TIMEOUT = 8;   // shorter timeout for link-status probes

    public function __construct(string $startUrl, array $opts = [], ?callable $progress = null)
    {
        $this->startUrl = $startUrl;
        $this->rootHost = root_host((string) parse_url($startUrl, PHP_URL_HOST));
        $this->maxPages = max(1, min(200, (int) ($opts['max_pages'] ?? 50)));
        $this->timeout  = max(5, min(30, (int) ($opts['timeout'] ?? 20)));
        $this->catFilter = $opts['categories'] ?? [];
        $this->progress = $progress;
    }

    private function emit(string $type, array $data): void
    {
        if ($this->progress) {
            ($this->progress)($type, $data);
        }
    }

    /**
     * Perform one HTTP request. Returns a structured response array.
     * $followRedirects=false records the FIRST response so redirects are visible.
     */
    public function fetch(string $url, string $method = 'GET', bool $followRedirects = true, ?int $timeout = null): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_NOBODY         => ($method === 'HEAD'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => $timeout ?? $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_ENCODING       => '',              // advertise gzip/deflate/br
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,*/*'],
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $info  = curl_getinfo($ch);
        curl_close($ch);

        $result = [
            'url'            => $url,
            'final_url'      => $info['url'] ?? $url,
            'status'         => (int) ($info['http_code'] ?? 0),
            'redirect_count' => (int) ($info['redirect_count'] ?? 0),
            'ttfb'           => (float) ($info['starttransfer_time'] ?? 0),
            'total_time'     => (float) ($info['total_time'] ?? 0),
            'size'           => (int) ($info['size_download'] ?? 0),
            'content_type'   => $info['content_type'] ?? '',
            'ssl_verify'     => (int) ($info['ssl_verify_result'] ?? -1),
            'primary_ip'     => $info['primary_ip'] ?? '',
            'headers'        => [],
            'body'           => '',
            'error'          => $errno ? ($err ?: ('cURL error ' . $errno)) : null,
            'errno'          => $errno,
        ];

        if ($raw !== false && $raw !== null) {
            $headerSize = (int) ($info['header_size'] ?? 0);
            $rawHeaders = substr($raw, 0, $headerSize);
            $result['body'] = substr($raw, $headerSize);
            // When redirects are followed, keep only the final header block.
            $blocks = preg_split("/\r\n\r\n|\n\n/", trim($rawHeaders));
            $lastBlock = is_array($blocks) ? end($blocks) : $rawHeaders;
            $result['headers'] = parse_headers_block((string) $lastBlock);
        }

        return $result;
    }

    /** Lightweight status probe for link checking (HEAD, fallback GET). */
    public function probe(string $url): array
    {
        $t = min(self::PROBE_TIMEOUT, $this->timeout);
        $r = $this->fetch($url, 'HEAD', true, $t);
        // Some servers reject HEAD (405) — retry with a GET.
        if ($r['status'] === 405 || $r['status'] === 501 || ($r['status'] === 0 && $r['errno'])) {
            $r = $this->fetch($url, 'GET', true, $t);
        }
        return $r;
    }

    /**
     * Run the full scan. Returns the complete findings structure.
     */
    public function run(): array
    {
        $checks   = new Checks($this, $this->rootHost, $this->catFilter);
        $findings = [];
        $pages    = [];

        $queue   = [$this->startUrl];
        $visited = [];
        $linkQueue = [];      // url => referring page (for broken-link checks)

        $this->emit('start', ['start_url' => $this->startUrl, 'max_pages' => $this->maxPages]);

        // ---- Site-wide, one-off checks (robots, sitemap, http->https, www) ----
        $siteFindings = $checks->siteWide($this->startUrl);
        foreach ($siteFindings as $f) {
            $findings[] = $f;
        }
        $this->emit('site', ['findings' => count($siteFindings)]);

        // ---- Breadth-first crawl of internal HTML pages ----
        $crawled = 0;
        while (!empty($queue) && $crawled < $this->maxPages) {
            $url = array_shift($queue);
            $key = rtrim($url, '/');
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;

            $resp = $this->fetch($url, 'GET', false);   // do NOT follow, to see redirects
            $crawled++;

            $pageFindings = [];

            // Redirect handling.
            if ($resp['status'] >= 300 && $resp['status'] < 400) {
                $loc = $resp['headers']['location'] ?? '';
                $target = $loc ? normalize_url(header_str($loc), $url) : null;
                $pageFindings = array_merge($pageFindings, $checks->redirect($url, $resp, $target));
                if ($target && same_site($target, $this->startUrl) && !isset($visited[rtrim($target, '/')])) {
                    $queue[] = $target;   // follow internal redirect target
                }
            } elseif ($resp['status'] >= 400 || $resp['status'] === 0) {
                $pageFindings = array_merge($pageFindings, $checks->httpError($url, $resp));
            } else {
                // 2xx — parse HTML and run the full battery.
                $isHtml = stripos($resp['content_type'], 'text/html') !== false;
                if ($isHtml) {
                    $parsed = $this->parseHtml($resp['body'], $resp['final_url'] ?: $url);
                    $pageFindings = array_merge(
                        $pageFindings,
                        $checks->page($url, $resp, $parsed)
                    );
                    // Enqueue new internal links.
                    foreach ($parsed['internal_links'] as $link) {
                        $lk = rtrim($link, '/');
                        if (!isset($visited[$lk]) && !in_array($link, $queue, true)) {
                            if (count($visited) + count($queue) < $this->maxPages * 3) {
                                $queue[] = $link;
                            }
                        }
                        if (!isset($linkQueue[$link])) {
                            $linkQueue[$link] = $url;
                        }
                    }
                    foreach ($parsed['external_links'] as $link) {
                        if (!isset($linkQueue[$link])) {
                            $linkQueue[$link] = $url;
                        }
                    }
                    // Record page meta for site-wide duplicate detection.
                    $pages[$url] = [
                        'title' => $parsed['title'],
                        'description' => $parsed['meta_description'],
                        'status' => $resp['status'],
                    ];
                }
            }

            foreach ($pageFindings as $f) {
                $findings[] = $f;
            }

            $this->emit('page', [
                'url'      => $url,
                'status'   => $resp['status'],
                'crawled'  => $crawled,
                'queued'   => count($queue),
                'findings' => count($pageFindings),
                'ttfb'     => round($resp['ttfb'] * 1000),
            ]);
        }

        // ---- Broken-link sweep over unique discovered links ----
        $this->emit('linkcheck_start', ['links' => min(count($linkQueue), self::MAX_LINK_CHECKS)]);
        $linkFindings = $this->checkLinks($linkQueue, $checks, $visited);
        foreach ($linkFindings as $f) {
            $findings[] = $f;
        }

        // ---- Cross-page duplicate title / description ----
        foreach ($checks->duplicates($pages) as $f) {
            $findings[] = $f;
        }

        // ---- Assemble summary ----
        $summary = $this->summarize($findings, $crawled);
        $this->emit('complete', ['total' => count($findings), 'score' => $summary['score']]);

        return [
            'start_url'    => $this->startUrl,
            'scanned_at'   => date('c'),
            'pages_crawled'=> $crawled,
            'findings'     => array_values($findings),
            'summary'      => $summary,
        ];
    }

    /** Check discovered links for broken (4xx/5xx/unreachable) targets. */
    private function checkLinks(array $linkQueue, Checks $checks, array $visited): array
    {
        $out = [];
        $checked = 0;
        foreach ($linkQueue as $link => $referrer) {
            if ($checked >= self::MAX_LINK_CHECKS) {
                break;
            }
            // Skip anything we already crawled and know the status of.
            if (isset($visited[rtrim($link, '/')])) {
                continue;
            }
            if (!preg_match('#^https?://#i', $link)) {
                continue;
            }
            $checked++;
            $r = $this->probe($link);
            $internal = same_site($link, $this->startUrl);
            if ($r['status'] >= 400 || ($r['status'] === 0 && $r['errno'])) {
                $out[] = $checks->brokenLink($link, $referrer, $r, $internal);
            }
            if ($checked % 20 === 0) {
                $this->emit('linkcheck', ['checked' => $checked]);
            }
        }
        $this->emit('linkcheck_done', ['checked' => $checked, 'broken' => count($out)]);
        return $out;
    }

    /**
     * Parse an HTML document with DOMDocument and extract everything the
     * checks need in one pass.
     */
    public function parseHtml(string $html, string $pageUrl): array
    {
        $out = [
            'title' => null, 'meta_description' => null, 'meta_robots' => null,
            'canonical' => null, 'lang' => null, 'viewport' => false,
            'h1' => [], 'headings' => [], 'og' => [], 'jsonld' => 0,
            'images_total' => 0, 'images_no_alt' => 0, 'large_inline' => 0,
            'inputs_total' => 0, 'inputs_no_label' => 0,
            'scripts_total' => 0, 'scripts_head_blocking' => 0,
            'styles_total' => 0, 'links_total' => 0, 'empty_links' => 0,
            'internal_links' => [], 'external_links' => [], 'assets' => [],
            'http_assets' => [], 'generator' => null, 'favicon' => false,
            'analytics' => false, 'word_count' => 0, 'meta_refresh' => null,
            'html_size' => strlen($html),
        ];

        if (trim($html) === '') {
            return $out;
        }

        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // Force UTF-8 handling.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($dom);

        // <html lang>
        $htmlEl = $dom->getElementsByTagName('html')->item(0);
        if ($htmlEl instanceof DOMElement) {
            $lang = $htmlEl->getAttribute('lang');
            $out['lang'] = $lang !== '' ? $lang : null;
        }

        // Title
        $titleEl = $dom->getElementsByTagName('title')->item(0);
        if ($titleEl) {
            $out['title'] = trim($titleEl->textContent);
        }

        // Meta tags
        foreach ($dom->getElementsByTagName('meta') as $m) {
            $name = strtolower($m->getAttribute('name'));
            $prop = strtolower($m->getAttribute('property'));
            $httpEquiv = strtolower($m->getAttribute('http-equiv'));
            $content = $m->getAttribute('content');
            if ($name === 'description') {
                $out['meta_description'] = trim($content);
            } elseif ($name === 'robots') {
                $out['meta_robots'] = strtolower($content);
            } elseif ($name === 'viewport') {
                $out['viewport'] = true;
            } elseif ($name === 'generator') {
                $out['generator'] = $content;
            } elseif (strpos($prop, 'og:') === 0) {
                $out['og'][$prop] = $content;
            } elseif ($httpEquiv === 'refresh') {
                $out['meta_refresh'] = $content;
            }
        }

        // Link tags: canonical, favicon, stylesheets
        foreach ($dom->getElementsByTagName('link') as $l) {
            $rel = strtolower($l->getAttribute('rel'));
            $href = $l->getAttribute('href');
            if ($rel === 'canonical') {
                $out['canonical'] = resolve_relative($pageUrl, $href) ?? $href;
            }
            if (strpos($rel, 'icon') !== false) {
                $out['favicon'] = true;
            }
            if ($rel === 'stylesheet') {
                $out['styles_total']++;
                $abs = resolve_relative($pageUrl, $href);
                if ($abs) {
                    $out['assets'][] = $abs;
                    if (stripos($abs, 'http://') === 0) {
                        $out['http_assets'][] = $abs;
                    }
                }
            }
        }

        // Headings
        foreach (['h1','h2','h3','h4','h5','h6'] as $i => $tag) {
            foreach ($dom->getElementsByTagName($tag) as $h) {
                $level = $i + 1;
                $out['headings'][] = $level;
                if ($level === 1) {
                    $out['h1'][] = trim($h->textContent);
                }
            }
        }

        // Images
        foreach ($dom->getElementsByTagName('img') as $img) {
            $out['images_total']++;
            $hasAlt = $img->hasAttribute('alt');
            $role = strtolower($img->getAttribute('role'));
            $ariaHidden = strtolower($img->getAttribute('aria-hidden'));
            if (!$hasAlt && $role !== 'presentation' && $ariaHidden !== 'true') {
                $out['images_no_alt']++;
            }
            $src = $img->getAttribute('src');
            if ($src) {
                $abs = resolve_relative($pageUrl, $src);
                if ($abs && stripos($abs, 'http://') === 0) {
                    $out['http_assets'][] = $abs;
                }
            }
        }

        // Scripts
        $head = $dom->getElementsByTagName('head')->item(0);
        foreach ($dom->getElementsByTagName('script') as $s) {
            $type = strtolower($s->getAttribute('type'));
            $src = $s->getAttribute('src');
            $inline = trim($s->textContent);
            if ($type === 'application/ld+json') {
                $out['jsonld']++;
                continue;
            }
            if ($src !== '') {
                $out['scripts_total']++;
                $abs = resolve_relative($pageUrl, $src);
                if ($abs) {
                    $out['assets'][] = $abs;
                    if (stripos($abs, 'http://') === 0) {
                        $out['http_assets'][] = $abs;
                    }
                }
                // Render-blocking = external script in <head> with no async/defer.
                $isInHead = $head && $this->isDescendant($s, $head);
                if ($isInHead && !$s->hasAttribute('async') && !$s->hasAttribute('defer')) {
                    $out['scripts_head_blocking']++;
                }
            }
            // Analytics detection.
            $hay = strtolower($src . ' ' . substr($inline, 0, 2000));
            if (preg_match('/googletagmanager|google-analytics|gtag\(|analytics\.js|ga\(|plausible|matomo|fathom|segment\.com|hotjar/', $hay)) {
                $out['analytics'] = true;
            }
        }

        // Forms / inputs
        foreach ($dom->getElementsByTagName('input') as $inp) {
            $type = strtolower($inp->getAttribute('type'));
            if (in_array($type, ['hidden','submit','button','image','reset'], true)) {
                continue;
            }
            $out['inputs_total']++;
            $id = $inp->getAttribute('id');
            $hasAria = $inp->hasAttribute('aria-label') || $inp->hasAttribute('aria-labelledby') || $inp->hasAttribute('title');
            $hasLabel = false;
            if ($id !== '') {
                $labels = $xp->query('//label[@for="' . addslashes($id) . '"]');
                $hasLabel = $labels && $labels->length > 0;
            }
            // wrapped-in-label case
            if (!$hasLabel) {
                $p = $inp->parentNode;
                while ($p instanceof DOMElement) {
                    if (strtolower($p->nodeName) === 'label') { $hasLabel = true; break; }
                    $p = $p->parentNode;
                }
            }
            if (!$hasLabel && !$hasAria) {
                $out['inputs_no_label']++;
            }
        }

        // Anchors
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            $text = trim($a->textContent);
            $hasAria = $a->hasAttribute('aria-label') || $a->hasAttribute('title');
            $hasImg = $a->getElementsByTagName('img')->length > 0;
            $out['links_total']++;
            if ($href !== '' && $text === '' && !$hasAria && !$hasImg) {
                $out['empty_links']++;
            }
            $abs = resolve_relative($pageUrl, $href);
            if ($abs === null) {
                continue;
            }
            // Non-page assets: worth a broken-link check but not a crawl target.
            if (preg_match('#\.(zip|pdf|jpg|jpeg|png|gif|webp|svg|mp4|mp3|css|js|xml|json|ico|woff2?|ttf)(\?|$)#i', $abs)) {
                $out['external_links'][] = $abs;
                continue;
            }
            if (same_site($abs, $pageUrl)) {
                $out['internal_links'][] = $abs;
            } else {
                $out['external_links'][] = $abs;
            }
        }

        $out['internal_links'] = array_values(array_unique($out['internal_links']));
        $out['external_links'] = array_values(array_unique($out['external_links']));
        $out['http_assets'] = array_values(array_unique($out['http_assets']));

        // Visible word count (strip script/style).
        $bodyText = $this->visibleText($dom);
        $out['word_count'] = str_word_count($bodyText);

        return $out;
    }

    private function isDescendant(DOMNode $node, DOMNode $ancestor): bool
    {
        $p = $node->parentNode;
        while ($p) {
            if ($p->isSameNode($ancestor)) {
                return true;
            }
            $p = $p->parentNode;
        }
        return false;
    }

    private function visibleText(DOMDocument $dom): string
    {
        foreach (['script','style','noscript','template'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            // Iterate backwards because the list is live.
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $n = $nodes->item($i);
                if ($n && $n->parentNode) {
                    $n->parentNode->removeChild($n);
                }
            }
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        $text = $body ? $body->textContent : $dom->textContent;
        return preg_replace('/\s+/', ' ', (string) $text);
    }

    /** Build the summary block (counts, score, breakdowns). */
    private function summarize(array $findings, int $pages): array
    {
        $bySeverity = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0];
        $byGroup = [];
        $byOwner = [];
        $penalty = 0;
        foreach ($findings as $f) {
            $sev = $f['severity'];
            $bySeverity[$sev] = ($bySeverity[$sev] ?? 0) + 1;
            $byGroup[$f['group']] = ($byGroup[$f['group']] ?? 0) + 1;
            $byOwner[$f['owner']] = ($byOwner[$f['owner']] ?? 0) + 1;
            $penalty += Catalog::severityWeight($sev);
        }
        // Health score: 100 minus scaled penalty, floored at 0.
        $score = max(0, 100 - (int) round($penalty * 0.6));
        arsort($byGroup);
        arsort($byOwner);
        return [
            'total'       => count($findings),
            'by_severity' => $bySeverity,
            'by_group'    => $byGroup,
            'by_owner'    => $byOwner,
            'score'       => $score,
            'grade'       => $this->grade($score),
            'pages'       => $pages,
        ];
    }

    private function grade(int $s): string
    {
        if ($s >= 90) return 'A';
        if ($s >= 80) return 'B';
        if ($s >= 70) return 'C';
        if ($s >= 55) return 'D';
        if ($s >= 40) return 'E';
        return 'F';
    }
}
