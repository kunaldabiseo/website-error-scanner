<?php
/**
 * Checks.php — turns raw responses + parsed HTML into catalog-mapped findings.
 *
 * Every finding is an associative array with the spreadsheet columns
 * (group, category, issue, severity, owner, meaning, fix, detect) plus live
 * evidence (page, detail).
 */

require_once __DIR__ . '/Catalog.php';
require_once __DIR__ . '/helpers.php';

class Checks
{
    private Scanner $scanner;
    private string $rootHost;
    private array $catFilter;   // enabled groups; empty = all
    private int $seq = 0;

    // Map a UI category key -> catalog "group" names it covers.
    private const GROUP_MAP = [
        'seo'           => ['SEO'],
        'technical'     => ['HTTP / Status', 'SSL / TLS', 'Security / Headers', 'DNS / Domain', 'WordPress'],
        'performance'   => ['Performance / Speed'],
        'accessibility' => ['UI / UX / Design'],
        'analytics'     => ['Analytics / Tracking'],
    ];

    public function __construct(Scanner $scanner, string $rootHost, array $catFilter = [])
    {
        $this->scanner = $scanner;
        $this->rootHost = $rootHost;
        $this->catFilter = $catFilter;
    }

    /** Is a catalog group enabled given the UI filter? */
    private function groupEnabled(string $group): bool
    {
        if (empty($this->catFilter)) {
            return true;
        }
        foreach ($this->catFilter as $catKey) {
            $groups = self::GROUP_MAP[$catKey] ?? [];
            if (in_array($group, $groups, true)) {
                return true;
            }
        }
        return false;
    }

    /** Compose a finding from a catalog key + live evidence. */
    private function make(string $key, string $page, string $detail, ?string $severity = null): ?array
    {
        $def = Catalog::get($key);
        if (!$this->groupEnabled($def['group'])) {
            return null;
        }
        $this->seq++;
        return [
            'id'       => 'F' . $this->seq,
            'key'      => $key,
            'group'    => $def['group'],
            'category' => $def['category'],
            'issue'    => $def['issue'],
            'severity' => $severity ?: $def['severity'],
            'owner'    => $def['owner'],
            'meaning'  => $def['meaning'],
            'fix'      => $def['fix'],
            'detect'   => $def['detect'],
            'page'     => $page,
            'detail'   => $detail,
        ];
    }

    /** Append non-null finding to list. */
    private function add(array &$out, ?array $finding): void
    {
        if ($finding !== null) {
            $out[] = $finding;
        }
    }

    // =================================================================
    // SITE-WIDE (run once)
    // =================================================================
    public function siteWide(string $startUrl): array
    {
        $out = [];
        $p = parse_url($startUrl);
        $scheme = $p['scheme'] ?? 'https';
        $host = $p['host'];
        $origin = $scheme . '://' . $host;

        // robots.txt
        $robots = $this->scanner->fetch($origin . '/robots.txt', 'GET', true);
        $robotsOk = $robots['status'] === 200 && stripos($robots['content_type'], 'text') !== false;
        if (!$robotsOk) {
            $this->add($out, $this->make('robots_missing', $origin . '/robots.txt', 'HTTP ' . $robots['status']));
        }

        // sitemap: from robots or common paths
        $sitemapFound = false;
        if ($robotsOk && preg_match('/^\s*sitemap:\s*(\S+)/im', $robots['body'])) {
            $sitemapFound = true;
        }
        if (!$sitemapFound) {
            foreach (['/sitemap.xml', '/sitemap_index.xml'] as $sp) {
                $sm = $this->scanner->probe($origin . $sp);
                if ($sm['status'] === 200) { $sitemapFound = true; break; }
            }
        }
        if (!$sitemapFound) {
            $this->add($out, $this->make('sitemap_missing', $origin, 'No sitemap in robots.txt or common paths'));
        }

        // HTTP -> HTTPS redirect (only meaningful if start is https)
        if ($scheme === 'https') {
            $httpResp = $this->scanner->fetch('http://' . $host . '/', 'GET', false);
            if ($httpResp['status'] >= 200 && $httpResp['status'] < 300) {
                $this->add($out, $this->make('no_http_https_redirect', 'http://' . $host . '/', 'HTTP version returns ' . $httpResp['status'] . ' without redirect'));
            }
            // SSL certificate inspection
            $this->add($out, $this->certCheck($host, $out));
            foreach ($this->certChecks($host) as $f) { $this->add($out, $f); }
        } else {
            $this->add($out, $this->make('no_https', $startUrl, 'Start URL uses http://'));
        }

        // www vs non-www both resolving without redirect
        $altHost = (strpos($host, 'www.') === 0) ? substr($host, 4) : 'www.' . $host;
        $altResp = $this->scanner->fetch($scheme . '://' . $altHost . '/', 'GET', false);
        if ($altResp['status'] >= 200 && $altResp['status'] < 300) {
            $this->add($out, $this->make('no_www_canonical', $scheme . '://' . $altHost . '/', $altHost . ' returns ' . $altResp['status'] . ' (expected 301 to canonical)'));
        }

        // WordPress hardening probes (only if WP detected via wp-json)
        $home = $this->scanner->fetch($startUrl, 'GET', true);
        $isWp = (isset($home['headers']['link']) && stripos(header_str($home['headers']['link']), '/wp-json/') !== false)
            || stripos($home['body'], '/wp-content/') !== false
            || stripos($home['body'], '/wp-includes/') !== false;
        if ($isWp && $this->groupEnabled('WordPress')) {
            $readme = $this->scanner->probe($origin . '/readme.html');
            if ($readme['status'] === 200) {
                $this->add($out, $this->make('wp_readme_exposed', $origin . '/readme.html', 'readme.html reachable (200)'));
            }
            $xmlrpc = $this->scanner->fetch($origin . '/xmlrpc.php', 'GET', true);
            if ($xmlrpc['status'] === 200 || $xmlrpc['status'] === 405 || stripos($xmlrpc['body'], 'XML-RPC server accepts') !== false) {
                $this->add($out, $this->make('wp_xmlrpc_exposed', $origin . '/xmlrpc.php', 'xmlrpc.php responds (' . $xmlrpc['status'] . ')'));
            }
            $users = $this->scanner->fetch($origin . '/wp-json/wp/v2/users', 'GET', true);
            if ($users['status'] === 200 && stripos($users['content_type'], 'json') !== false && preg_match('/"slug"\s*:/', $users['body'])) {
                $this->add($out, $this->make('wp_users_exposed', $origin . '/wp-json/wp/v2/users', 'REST users endpoint lists accounts'));
            }
        }

        return array_values(array_filter($out));
    }

    /** Certificate expiry / validity via a raw TLS handshake. */
    private function certCheck(string $host, array &$existing): ?array
    {
        return null; // handled by certChecks(); kept for signature symmetry
    }

    private function certChecks(string $host): array
    {
        $out = [];
        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $client = @stream_socket_client(
            'ssl://' . $host . ':443',
            $errno, $errstr, 10,
            STREAM_CLIENT_CONNECT, $ctx
        );
        if ($client === false) {
            return $out; // connection issues already surface elsewhere
        }
        $params = stream_context_get_params($client);
        fclose($client);
        if (empty($params['options']['ssl']['peer_certificate'])) {
            return $out;
        }
        $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        if (!$cert) {
            return $out;
        }
        $now = time();
        $validTo = $cert['validTo_time_t'] ?? 0;
        if ($validTo > 0) {
            $daysLeft = (int) floor(($validTo - $now) / 86400);
            if ($validTo < $now) {
                $this->add($out, $this->make('cert_expired', 'https://' . $host, 'Expired ' . abs($daysLeft) . ' day(s) ago'));
            } elseif ($daysLeft <= 21) {
                $this->add($out, $this->make('cert_expiring', 'https://' . $host, 'Expires in ' . $daysLeft . ' day(s)'));
            }
        }
        // Hostname coverage
        $names = [];
        if (!empty($cert['subject']['CN'])) {
            $names[] = $cert['subject']['CN'];
        }
        if (!empty($cert['extensions']['subjectAltName'])) {
            foreach (explode(',', $cert['extensions']['subjectAltName']) as $san) {
                $san = trim(str_replace('DNS:', '', $san));
                if ($san !== '') { $names[] = $san; }
            }
        }
        if (!empty($names) && !$this->hostMatchesCert($host, $names)) {
            $this->add($out, $this->make('cert_host_mismatch', 'https://' . $host, 'Cert covers: ' . implode(', ', array_slice($names, 0, 5))));
        }
        return array_values(array_filter($out));
    }

    private function hostMatchesCert(string $host, array $names): bool
    {
        foreach ($names as $n) {
            $n = strtolower($n);
            if ($n === strtolower($host)) {
                return true;
            }
            if (strpos($n, '*.') === 0) {
                $suffix = substr($n, 1); // ".example.com"
                if (substr(strtolower($host), -strlen($suffix)) === $suffix) {
                    return true;
                }
            }
        }
        return false;
    }

    // =================================================================
    // REDIRECTS
    // =================================================================
    public function redirect(string $url, array $resp, ?string $target): array
    {
        $out = [];
        $status = $resp['status'];
        $detail = 'HTTP ' . $status . ($target ? ' -> ' . $target : '');
        if ($status === 302 || $status === 307) {
            $this->add($out, $this->make('redirect_302', $url, $detail));
        }
        // Redirect chain: follow and count hops.
        $followed = $this->scanner->fetch($url, 'GET', true);
        if (($followed['redirect_count'] ?? 0) >= 2) {
            $this->add($out, $this->make('redirect_chain', $url, $followed['redirect_count'] . ' hops -> ' . $followed['final_url']));
        }
        return array_values(array_filter($out));
    }

    // =================================================================
    // HTTP ERRORS
    // =================================================================
    public function httpError(string $url, array $resp): array
    {
        $out = [];
        $status = $resp['status'];
        if ($status >= 500 || ($status === 0 && $resp['errno'])) {
            $detail = $status === 0 ? ('Unreachable: ' . ($resp['error'] ?? 'connection failed')) : ('HTTP ' . $status);
            $this->add($out, $this->make('http_5xx', $url, $detail));
        } elseif ($status >= 400) {
            $this->add($out, $this->make('http_4xx', $url, 'HTTP ' . $status));
        }
        return array_values(array_filter($out));
    }

    /** A broken link discovered during the link sweep. */
    public function brokenLink(string $url, string $referrer, array $resp, bool $internal): array
    {
        $status = $resp['status'];
        $detail = ($status === 0 ? ('Unreachable: ' . ($resp['error'] ?? 'failed')) : ('HTTP ' . $status)) . ' · linked from ' . $referrer;
        $key = $internal ? 'broken_internal_link' : 'broken_external_link';
        $f = $this->make($key, $url, $detail);
        return $f ? [$f] : [];
    }

    // =================================================================
    // PER-PAGE (2xx HTML)
    // =================================================================
    public function page(string $url, array $resp, array $p): array
    {
        $out = [];
        $headers = $resp['headers'];
        $isHttps = stripos($url, 'https://') === 0;

        // ---- Security headers (check once, on the entry page ideally, but cheap per page) ----
        // To avoid noise we only flag header issues on the start/home document set:
        $flagHeaders = true;
        if ($flagHeaders) {
            if ($isHttps && empty($headers['strict-transport-security'])) {
                $this->add($out, $this->make('missing_hsts', $url, 'No Strict-Transport-Security header'));
            }
            if (empty($headers['content-security-policy'])) {
                $this->add($out, $this->make('missing_csp', $url, 'No Content-Security-Policy header'));
            }
            if (empty($headers['x-content-type-options'])) {
                $this->add($out, $this->make('missing_xcto', $url, 'No X-Content-Type-Options header'));
            }
            if (empty($headers['x-frame-options']) && (empty($headers['content-security-policy']) || stripos(header_str($headers['content-security-policy'] ?? ''), 'frame-ancestors') === false)) {
                $this->add($out, $this->make('missing_xfo', $url, 'No X-Frame-Options or frame-ancestors'));
            }
            if (empty($headers['referrer-policy'])) {
                $this->add($out, $this->make('missing_referrer', $url, 'No Referrer-Policy header'));
            }
            $serverLeak = [];
            if (!empty($headers['server']) && preg_match('#/\d#', header_str($headers['server']))) {
                $serverLeak[] = 'Server: ' . header_str($headers['server']);
            }
            if (!empty($headers['x-powered-by'])) {
                $serverLeak[] = 'X-Powered-By: ' . header_str($headers['x-powered-by']);
            }
            if ($serverLeak) {
                $this->add($out, $this->make('server_version_leak', $url, implode(' · ', $serverLeak)));
            }
        }

        // ---- Performance (headers + parsed) ----
        $ce = header_str($headers['content-encoding'] ?? '');
        if ($ce === '' && $resp['size'] > 4096) {
            $this->add($out, $this->make('no_compression', $url, 'No Content-Encoding on ' . human_bytes($resp['size']) . ' response'));
        }
        if (empty($headers['cache-control']) && empty($headers['expires'])) {
            $this->add($out, $this->make('no_cache_headers', $url, 'No Cache-Control or Expires header'));
        }
        if ($resp['ttfb'] > 1.2) {
            $this->add($out, $this->make('slow_ttfb', $url, 'TTFB ' . round($resp['ttfb'] * 1000) . ' ms'));
        }
        if ($p['html_size'] > 500000) {
            $this->add($out, $this->make('heavy_page', $url, 'HTML ' . human_bytes($p['html_size'])));
        }
        $assetCount = $p['scripts_total'] + $p['styles_total'] + $p['images_total'];
        if ($assetCount > 90) {
            $this->add($out, $this->make('too_many_requests', $url, $assetCount . ' referenced assets (' . $p['scripts_total'] . ' JS, ' . $p['styles_total'] . ' CSS, ' . $p['images_total'] . ' img)'));
        }
        if ($p['scripts_head_blocking'] > 0) {
            $this->add($out, $this->make('render_blocking', $url, $p['scripts_head_blocking'] . ' blocking script(s) in <head>'));
        }

        // ---- Mixed content ----
        if ($isHttps && !empty($p['http_assets'])) {
            $this->add($out, $this->make('mixed_content', $url, count($p['http_assets']) . ' insecure asset(s), e.g. ' . $p['http_assets'][0]));
        }

        // ---- SEO ----
        $robots = $p['meta_robots'] ?? '';
        $xrobots = strtolower(header_str($headers['x-robots-tag'] ?? ''));
        if (strpos($robots, 'noindex') !== false || strpos($xrobots, 'noindex') !== false) {
            $this->add($out, $this->make('noindex', $url, 'robots: ' . ($robots ?: $xrobots)));
        }
        if ($p['title'] === null || $p['title'] === '') {
            $this->add($out, $this->make('title_missing', $url, 'No <title>'));
        } else {
            $len = mb_strlen($p['title']);
            if ($len < 30 || $len > 62) {
                $this->add($out, $this->make('title_length', $url, $len . ' chars: "' . mb_substr($p['title'], 0, 70) . '"'));
            }
        }
        if ($p['meta_description'] === null || $p['meta_description'] === '') {
            $this->add($out, $this->make('meta_desc_missing', $url, 'No meta description'));
        } else {
            $len = mb_strlen($p['meta_description']);
            if ($len < 70 || $len > 160) {
                $this->add($out, $this->make('meta_desc_length', $url, $len . ' chars'));
            }
        }
        $h1count = count($p['h1']);
        if ($h1count === 0) {
            $this->add($out, $this->make('h1_missing', $url, 'No <h1> found'));
        } elseif ($h1count > 1) {
            $this->add($out, $this->make('h1_multiple', $url, $h1count . ' <h1> tags'));
        }
        if ($p['canonical'] === null) {
            $this->add($out, $this->make('canonical_missing', $url, 'No rel=canonical'));
        }
        if (empty($p['og'])) {
            $this->add($out, $this->make('og_missing', $url, 'No Open Graph tags'));
        }
        if ($p['jsonld'] === 0) {
            $this->add($out, $this->make('structured_data_missing', $url, 'No JSON-LD blocks'));
        }
        if ($p['word_count'] > 0 && $p['word_count'] < 120) {
            $this->add($out, $this->make('thin_content', $url, $p['word_count'] . ' words'));
        }
        if ($p['meta_refresh'] !== null) {
            $this->add($out, $this->make('meta_refresh', $url, 'meta refresh: ' . $p['meta_refresh']));
        }

        // ---- WordPress generator leak ----
        if ($p['generator'] && stripos($p['generator'], 'wordpress') !== false && preg_match('/[\d.]+/', $p['generator'])) {
            $this->add($out, $this->make('wp_version_leak', $url, $p['generator']));
        }

        // ---- Accessibility / UI ----
        if ($p['images_no_alt'] > 0) {
            $this->add($out, $this->make('img_no_alt', $url, $p['images_no_alt'] . ' of ' . $p['images_total'] . ' images missing alt'));
        }
        if ($p['inputs_no_label'] > 0) {
            $this->add($out, $this->make('input_no_label', $url, $p['inputs_no_label'] . ' unlabelled input(s)'));
        }
        if (!$p['viewport']) {
            $this->add($out, $this->make('no_viewport', $url, 'No viewport meta'));
        }
        if ($p['lang'] === null) {
            $this->add($out, $this->make('no_lang', $url, 'No lang attribute'));
        }
        if ($p['empty_links'] > 0) {
            $this->add($out, $this->make('empty_link', $url, $p['empty_links'] . ' link(s) with no text'));
        }
        if (!$p['favicon']) {
            $this->add($out, $this->make('no_favicon', $url, 'No favicon link'));
        }
        // Heading skip detection.
        $prev = 0; $skip = false;
        foreach ($p['headings'] as $lvl) {
            if ($prev > 0 && $lvl > $prev + 1) { $skip = true; break; }
            $prev = $lvl;
        }
        if ($skip) {
            $this->add($out, $this->make('heading_skip', $url, 'Heading levels jump (e.g. skipped level)'));
        }

        // ---- Analytics ----
        if (!$p['analytics']) {
            $this->add($out, $this->make('no_analytics', $url, 'No analytics/tag manager snippet detected'));
        }

        return array_values(array_filter($out));
    }

    // =================================================================
    // CROSS-PAGE DUPLICATES
    // =================================================================
    public function duplicates(array $pages): array
    {
        $out = [];
        $titles = [];
        $descs = [];
        foreach ($pages as $url => $meta) {
            if (!empty($meta['title'])) {
                $titles[$meta['title']][] = $url;
            }
            if (!empty($meta['description'])) {
                $descs[$meta['description']][] = $url;
            }
        }
        foreach ($titles as $title => $urls) {
            if (count($urls) > 1) {
                $f = $this->make('title_duplicate', $urls[0], count($urls) . ' pages share title "' . mb_substr($title, 0, 60) . '" (e.g. ' . $urls[1] . ')');
                if ($f) { $out[] = $f; }
            }
        }
        foreach ($descs as $desc => $urls) {
            if (count($urls) > 1) {
                $f = $this->make('meta_desc_duplicate', $urls[0], count($urls) . ' pages share the same meta description');
                if ($f) { $out[] = $f; }
            }
        }
        return $out;
    }
}
