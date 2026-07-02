<?php
/**
 * Catalog.php
 * -----------
 * Maps every automatically-detectable check to the same columns used in the
 * "Website Error & Issue Catalog" spreadsheet:
 *   Group · Category · Error/Issue · Type · Meaning/Cause · Fix/Action · Owner · Detect With · Severity
 *
 * Each check emitted by Checks.php references a KEY in this catalog. The scanner
 * attaches the live evidence (which page, what value) to the static reference here.
 */

class Catalog
{
    /**
     * Master definition table. Keyed by internal check code.
     * severity: Critical | High | Medium | Low
     */
    public static function defs(): array
    {
        return [
            // ---------------------------------------------------------------
            // HTTP / STATUS
            // ---------------------------------------------------------------
            'http_4xx' => [
                'group' => 'HTTP / Status', 'category' => 'HTTP · 4xx Client Error',
                'issue' => 'Page returns 4xx client error',
                'meaning' => 'The URL responds with a 4xx status (e.g. 404 Not Found, 403 Forbidden, 410 Gone). The resource is missing, blocked, or removed.',
                'fix' => 'Restore the page, fix the link, or set up a 301 redirect to a relevant replacement. Remove internal links pointing at it.',
                'owner' => 'Backend Developer', 'detect' => 'Crawler · Server logs · GSC Coverage', 'severity' => 'High',
            ],
            'http_5xx' => [
                'group' => 'HTTP / Status', 'category' => 'HTTP · 5xx Server Error',
                'issue' => 'Page returns 5xx server error',
                'meaning' => 'The server failed to fulfil the request (500 Internal, 502 Bad Gateway, 503 Unavailable, 504 Timeout). Indicates a crash, misconfiguration, or overload.',
                'fix' => 'Check server/PHP error logs, resource limits and upstream services. 5xx errors block users and search engines.',
                'owner' => 'DevOps / SysAdmin', 'detect' => 'Crawler · Server logs · Uptime monitor', 'severity' => 'Critical',
            ],
            'broken_internal_link' => [
                'group' => 'HTTP / Status', 'category' => 'HTTP · Broken Links',
                'issue' => 'Broken internal link (404/5xx)',
                'meaning' => 'A link within your own site points to a URL that returns an error. Wastes crawl budget and frustrates users.',
                'fix' => 'Update the anchor href to the correct URL, or 301 the target. Audit templates/menus if the link is site-wide.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · Screaming Frog', 'severity' => 'High',
            ],
            'broken_external_link' => [
                'group' => 'HTTP / Status', 'category' => 'HTTP · Broken Links',
                'issue' => 'Broken external link (404/5xx/unreachable)',
                'meaning' => 'A link to another website is dead or unreachable. Hurts UX and signals low maintenance.',
                'fix' => 'Update the link to a working URL, link to an archived copy, or remove it.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · Link checker', 'severity' => 'Medium',
            ],
            'redirect_chain' => [
                'group' => 'HTTP / Status', 'category' => 'HTTP · 3xx Redirects',
                'issue' => 'Redirect chain (multiple hops)',
                'meaning' => 'A URL redirects through two or more hops before reaching the final page. Slows load and dilutes link equity.',
                'fix' => 'Point the first URL directly at the final destination in one 301 hop.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler · Redirect checker', 'severity' => 'Medium',
            ],
            'redirect_302' => [
                'group' => 'HTTP / Status', 'category' => 'HTTP · 3xx Redirects',
                'issue' => 'Temporary (302/307) redirect used for a permanent move',
                'meaning' => 'A 302/307 keeps the original URL indexed and does not pass full link equity. Often used by mistake where a 301 is intended.',
                'fix' => 'Use a 301 Moved Permanently for permanent URL changes.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler · Redirect checker', 'severity' => 'Medium',
            ],
            'meta_refresh' => [
                'group' => 'HTTP / Status', 'category' => 'HTTP · 3xx Redirects',
                'issue' => 'Meta-refresh / JavaScript redirect',
                'meaning' => 'Client-side redirect via a meta refresh tag or JS. Slower and weaker for SEO than a server 301.',
                'fix' => 'Replace with a server-side 301 redirect.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · Page source', 'severity' => 'Low',
            ],

            // ---------------------------------------------------------------
            // SSL / TLS
            // ---------------------------------------------------------------
            'no_https' => [
                'group' => 'SSL / TLS', 'category' => 'SSL · Availability',
                'issue' => 'Site not served over HTTPS',
                'meaning' => 'The page is available over plain HTTP without encryption. Browsers mark it "Not secure" and search engines penalise it.',
                'fix' => 'Install a TLS certificate (e.g. Let\'s Encrypt) and serve all traffic over HTTPS.',
                'owner' => 'DevOps / SysAdmin', 'detect' => 'Crawler · Browser address bar', 'severity' => 'Critical',
            ],
            'no_http_https_redirect' => [
                'group' => 'SSL / TLS', 'category' => 'SSL · Redirect',
                'issue' => 'HTTP does not redirect to HTTPS',
                'meaning' => 'The insecure http:// version loads without redirecting to https://, leaving a duplicate insecure entry point.',
                'fix' => 'Add a permanent 301 redirect from http:// to https:// at the server/CDN level.',
                'owner' => 'DevOps / SysAdmin', 'detect' => 'Crawler · curl -I', 'severity' => 'High',
            ],
            'cert_expired' => [
                'group' => 'SSL / TLS', 'category' => 'SSL · Certificate',
                'issue' => 'TLS certificate expired',
                'meaning' => 'The SSL certificate is past its expiry date. Browsers block the site with a full-page security warning.',
                'fix' => 'Renew/reissue the certificate immediately and automate renewal.',
                'owner' => 'DevOps / SysAdmin', 'detect' => 'openssl · Crawler', 'severity' => 'Critical',
            ],
            'cert_expiring' => [
                'group' => 'SSL / TLS', 'category' => 'SSL · Certificate',
                'issue' => 'TLS certificate expiring soon',
                'meaning' => 'The certificate expires within 21 days. Risk of an outage if renewal is missed.',
                'fix' => 'Renew now and enable auto-renewal + expiry monitoring.',
                'owner' => 'DevOps / SysAdmin', 'detect' => 'openssl · Crawler', 'severity' => 'Medium',
            ],
            'cert_host_mismatch' => [
                'group' => 'SSL / TLS', 'category' => 'SSL · Certificate',
                'issue' => 'Certificate hostname mismatch',
                'meaning' => 'The certificate does not cover the requested hostname, causing browser warnings.',
                'fix' => 'Reissue a certificate that includes this hostname (or a wildcard/SAN).',
                'owner' => 'DevOps / SysAdmin', 'detect' => 'openssl · Crawler', 'severity' => 'High',
            ],
            'mixed_content' => [
                'group' => 'SSL / TLS', 'category' => 'SSL · Mixed Content',
                'issue' => 'Mixed content (HTTP asset on HTTPS page)',
                'meaning' => 'A secure page loads scripts, styles, images or iframes over insecure HTTP. Browsers block or warn, breaking the padlock.',
                'fix' => 'Serve every asset over HTTPS (or use absolute https URLs).',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · Browser console', 'severity' => 'High',
            ],

            // ---------------------------------------------------------------
            // SECURITY / HEADERS
            // ---------------------------------------------------------------
            'missing_hsts' => [
                'group' => 'Security / Headers', 'category' => 'Security · Headers',
                'issue' => 'Missing Strict-Transport-Security (HSTS)',
                'meaning' => 'Without HSTS, browsers may still attempt insecure HTTP connections, exposing users to downgrade/MITM attacks.',
                'fix' => 'Send Strict-Transport-Security: max-age=31536000; includeSubDomains (add preload once verified).',
                'owner' => 'Security Specialist', 'detect' => 'Response headers · securityheaders.com', 'severity' => 'Medium',
            ],
            'missing_csp' => [
                'group' => 'Security / Headers', 'category' => 'Security · Headers',
                'issue' => 'Missing Content-Security-Policy',
                'meaning' => 'No CSP header, so the browser has no allow-list of script/style sources — a key defence against XSS is absent.',
                'fix' => 'Define a Content-Security-Policy header, starting in report-only mode to tune it.',
                'owner' => 'Security Specialist', 'detect' => 'Response headers · securityheaders.com', 'severity' => 'Medium',
            ],
            'missing_xcto' => [
                'group' => 'Security / Headers', 'category' => 'Security · Headers',
                'issue' => 'Missing X-Content-Type-Options',
                'meaning' => 'Without nosniff, browsers may MIME-sniff responses, enabling some content-type attacks.',
                'fix' => 'Send X-Content-Type-Options: nosniff.',
                'owner' => 'Security Specialist', 'detect' => 'Response headers', 'severity' => 'Low',
            ],
            'missing_xfo' => [
                'group' => 'Security / Headers', 'category' => 'Security · Headers',
                'issue' => 'Missing X-Frame-Options / frame-ancestors',
                'meaning' => 'No clickjacking protection — the page can be embedded in a malicious iframe.',
                'fix' => 'Send X-Frame-Options: SAMEORIGIN or a CSP frame-ancestors directive.',
                'owner' => 'Security Specialist', 'detect' => 'Response headers', 'severity' => 'Low',
            ],
            'missing_referrer' => [
                'group' => 'Security / Headers', 'category' => 'Security · Headers',
                'issue' => 'Missing Referrer-Policy',
                'meaning' => 'Without a Referrer-Policy, full URLs (possibly with sensitive query strings) may leak to third parties.',
                'fix' => 'Send Referrer-Policy: strict-origin-when-cross-origin.',
                'owner' => 'Security Specialist', 'detect' => 'Response headers', 'severity' => 'Low',
            ],
            'server_version_leak' => [
                'group' => 'Security / Headers', 'category' => 'Security · Information Disclosure',
                'issue' => 'Server / technology version disclosed',
                'meaning' => 'Server or X-Powered-By headers reveal exact software versions, helping attackers target known CVEs.',
                'fix' => 'Suppress version tokens (server_tokens off; expose_php off; remove X-Powered-By).',
                'owner' => 'DevOps / SysAdmin', 'detect' => 'Response headers', 'severity' => 'Low',
            ],

            // ---------------------------------------------------------------
            // SEO
            // ---------------------------------------------------------------
            'title_missing' => [
                'group' => 'SEO', 'category' => 'SEO · On-Page',
                'issue' => 'Missing title tag',
                'meaning' => 'The page has no title element. Search engines have nothing to show as the clickable result headline.',
                'fix' => 'Add a unique, descriptive title (about 50-60 chars) with the primary keyword.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler · View source', 'severity' => 'High',
            ],
            'title_length' => [
                'group' => 'SEO', 'category' => 'SEO · On-Page',
                'issue' => 'Title too long or too short',
                'meaning' => 'Titles under ~30 or over ~60 characters get truncated or look thin in search results.',
                'fix' => 'Rewrite the title to about 50-60 characters.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler', 'severity' => 'Low',
            ],
            'title_duplicate' => [
                'group' => 'SEO', 'category' => 'SEO · Duplication',
                'issue' => 'Duplicate title across pages',
                'meaning' => 'Multiple pages share the same title, causing keyword cannibalisation and weak differentiation in search.',
                'fix' => 'Give every page a unique, intent-specific title.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler', 'severity' => 'Medium',
            ],
            'meta_desc_missing' => [
                'group' => 'SEO', 'category' => 'SEO · On-Page',
                'issue' => 'Missing meta description',
                'meaning' => 'No meta description, so search engines auto-generate the snippet — usually less compelling, lowering CTR.',
                'fix' => 'Add a unique meta description (about 120-158 chars) that sells the click.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler', 'severity' => 'Medium',
            ],
            'meta_desc_length' => [
                'group' => 'SEO', 'category' => 'SEO · On-Page',
                'issue' => 'Meta description length sub-optimal',
                'meaning' => 'Description is very short or over ~160 characters and will be truncated in the SERP.',
                'fix' => 'Aim for about 120-158 characters.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler', 'severity' => 'Low',
            ],
            'meta_desc_duplicate' => [
                'group' => 'SEO', 'category' => 'SEO · Duplication',
                'issue' => 'Duplicate meta description across pages',
                'meaning' => 'Several pages reuse the same description, reducing snippet relevance and differentiation.',
                'fix' => 'Write a unique description per page.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler', 'severity' => 'Low',
            ],
            'h1_missing' => [
                'group' => 'SEO', 'category' => 'SEO · On-Page',
                'issue' => 'Missing H1 heading',
                'meaning' => 'No H1 on the page. Weakens topical clarity for users and search engines.',
                'fix' => 'Add exactly one descriptive H1 summarising the page.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler', 'severity' => 'Medium',
            ],
            'h1_multiple' => [
                'group' => 'SEO', 'category' => 'SEO · On-Page',
                'issue' => 'Multiple H1 headings',
                'meaning' => 'More than one H1 dilutes the primary topic signal.',
                'fix' => 'Keep one H1; demote the rest to H2/H3.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler', 'severity' => 'Low',
            ],
            'canonical_missing' => [
                'group' => 'SEO', 'category' => 'SEO · Canonicalisation',
                'issue' => 'Missing canonical tag',
                'meaning' => 'No rel="canonical" link, raising the risk of duplicate-content dilution from URL variants.',
                'fix' => 'Add a self-referencing canonical link to every indexable page.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler', 'severity' => 'Low',
            ],
            'noindex' => [
                'group' => 'SEO', 'category' => 'SEO · Indexability',
                'issue' => 'Page blocked by noindex',
                'meaning' => 'A meta robots or X-Robots-Tag noindex keeps this page out of search results. Fine if intended, harmful if not.',
                'fix' => 'Remove noindex from pages that should rank.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler · GSC', 'severity' => 'High',
            ],
            'robots_missing' => [
                'group' => 'SEO', 'category' => 'SEO · Crawlability',
                'issue' => 'robots.txt missing',
                'meaning' => 'No robots.txt found. Crawlers get no directives and cannot find the declared sitemap.',
                'fix' => 'Add a robots.txt at the domain root with a Sitemap: directive.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler · /robots.txt', 'severity' => 'Low',
            ],
            'sitemap_missing' => [
                'group' => 'SEO', 'category' => 'SEO · Crawlability',
                'issue' => 'XML sitemap missing',
                'meaning' => 'No XML sitemap detected in robots.txt or at common paths. Slows discovery of new/updated pages.',
                'fix' => 'Generate an XML sitemap and reference it in robots.txt + Search Console.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler · /sitemap.xml', 'severity' => 'Low',
            ],
            'og_missing' => [
                'group' => 'SEO', 'category' => 'SEO · Social / Open Graph',
                'issue' => 'Missing Open Graph tags',
                'meaning' => 'No og:title/og:image, so shared links on social/chat render poorly, lowering share CTR.',
                'fix' => 'Add og:title, og:description, og:image and Twitter Card tags.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler · Social debuggers', 'severity' => 'Low',
            ],
            'structured_data_missing' => [
                'group' => 'SEO', 'category' => 'SEO · Structured Data',
                'issue' => 'No structured data (Schema.org)',
                'meaning' => 'No JSON-LD/microdata detected, so the page is unlikely to earn rich results (stars, FAQ, breadcrumbs).',
                'fix' => 'Add relevant JSON-LD schema (Organization, Product, Article, Breadcrumb...).',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler · Rich Results Test', 'severity' => 'Low',
            ],
            'thin_content' => [
                'group' => 'SEO', 'category' => 'SEO · Content',
                'issue' => 'Thin content (very low word count)',
                'meaning' => 'The page has very little visible text, which may be seen as low value.',
                'fix' => 'Expand with useful, original content or consolidate/redirect.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler', 'severity' => 'Low',
            ],

            // ---------------------------------------------------------------
            // PERFORMANCE / SPEED
            // ---------------------------------------------------------------
            'no_compression' => [
                'group' => 'Performance / Speed', 'category' => 'Performance · Transfer',
                'issue' => 'Text response not compressed',
                'meaning' => 'HTML/CSS/JS is served without gzip or brotli, inflating transfer size and load time.',
                'fix' => 'Enable gzip/brotli compression for text responses at the server/CDN.',
                'owner' => 'DevOps / SysAdmin', 'detect' => 'Response headers (Content-Encoding)', 'severity' => 'Medium',
            ],
            'no_cache_headers' => [
                'group' => 'Performance / Speed', 'category' => 'Performance · Caching',
                'issue' => 'Missing cache headers',
                'meaning' => 'No Cache-Control/Expires, so browsers re-download assets every visit.',
                'fix' => 'Set long-lived Cache-Control (immutable) on fingerprinted static assets.',
                'owner' => 'DevOps / SysAdmin', 'detect' => 'Response headers', 'severity' => 'Low',
            ],
            'heavy_page' => [
                'group' => 'Performance / Speed', 'category' => 'Performance · Page Weight',
                'issue' => 'Large HTML document',
                'meaning' => 'The HTML payload is unusually large, slowing first render and increasing parse time.',
                'fix' => 'Reduce inline data/markup; paginate; lazy-load; strip unused code.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · DevTools', 'severity' => 'Low',
            ],
            'too_many_requests' => [
                'group' => 'Performance / Speed', 'category' => 'Performance · Requests',
                'issue' => 'High number of render assets',
                'meaning' => 'The page references many CSS/JS/image files, increasing round-trips and blocking render.',
                'fix' => 'Bundle/minify, defer non-critical JS, and lazy-load images.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · DevTools', 'severity' => 'Low',
            ],
            'render_blocking' => [
                'group' => 'Performance / Speed', 'category' => 'Performance · Render Blocking',
                'issue' => 'Render-blocking scripts in head',
                'meaning' => 'Synchronous script tags in the head block HTML parsing and delay first paint.',
                'fix' => 'Add defer/async or move scripts before the closing body tag; inline critical CSS.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · Lighthouse', 'severity' => 'Low',
            ],
            'slow_ttfb' => [
                'group' => 'Performance / Speed', 'category' => 'Performance · Server Response',
                'issue' => 'Slow server response (TTFB)',
                'meaning' => 'Time to first byte is high, meaning the server is slow to start responding.',
                'fix' => 'Add caching, optimise DB queries, upgrade hosting, or use a CDN.',
                'owner' => 'DevOps / SysAdmin', 'detect' => 'Crawler timing · Lighthouse', 'severity' => 'Medium',
            ],
            'large_image' => [
                'group' => 'Performance / Speed', 'category' => 'Performance · Images',
                'issue' => 'Oversized image asset',
                'meaning' => 'An image is very large in bytes, a common cause of slow LCP.',
                'fix' => 'Compress, resize to display size, and serve modern formats (WebP/AVIF).',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · DevTools', 'severity' => 'Low',
            ],

            // ---------------------------------------------------------------
            // UI / UX / ACCESSIBILITY
            // ---------------------------------------------------------------
            'img_no_alt' => [
                'group' => 'UI / UX / Design', 'category' => 'Accessibility · Images',
                'issue' => 'Images missing alt text',
                'meaning' => 'Images without alt attributes are invisible to screen readers and lose image-search value.',
                'fix' => 'Add descriptive alt text (or alt="" for purely decorative images).',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · axe · WAVE', 'severity' => 'Medium',
            ],
            'input_no_label' => [
                'group' => 'UI / UX / Design', 'category' => 'Accessibility · Forms',
                'issue' => 'Form field without a label',
                'meaning' => 'Inputs lacking a label/aria-label are inaccessible and confusing to assistive tech.',
                'fix' => 'Associate every input with a label (for=) or aria-label.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · axe', 'severity' => 'Medium',
            ],
            'no_viewport' => [
                'group' => 'UI / UX / Design', 'category' => 'UX · Mobile',
                'issue' => 'Missing responsive viewport meta',
                'meaning' => 'No viewport meta tag — the page will not scale correctly on mobile.',
                'fix' => 'Add a viewport meta tag: width=device-width, initial-scale=1.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · Mobile-friendly test', 'severity' => 'Medium',
            ],
            'no_lang' => [
                'group' => 'UI / UX / Design', 'category' => 'Accessibility · Semantics',
                'issue' => 'Missing lang attribute on html element',
                'meaning' => 'No lang attribute, so screen readers cannot pick the correct pronunciation/voice.',
                'fix' => 'Set lang="en" (or the correct language code) on the html element.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · axe', 'severity' => 'Low',
            ],
            'heading_skip' => [
                'group' => 'UI / UX / Design', 'category' => 'Accessibility · Structure',
                'issue' => 'Skipped heading level',
                'meaning' => 'Heading levels jump (e.g. H2 to H4), breaking the document outline for assistive tech.',
                'fix' => 'Use sequential heading levels without skipping.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · axe', 'severity' => 'Low',
            ],
            'empty_link' => [
                'group' => 'UI / UX / Design', 'category' => 'Accessibility · Links',
                'issue' => 'Link with no discernible text',
                'meaning' => 'An anchor has no text/aria-label (e.g. icon-only), so screen readers announce it as "link".',
                'fix' => 'Add visible text or an aria-label describing the destination.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler · axe', 'severity' => 'Low',
            ],
            'no_favicon' => [
                'group' => 'UI / UX / Design', 'category' => 'UX · Branding',
                'issue' => 'No favicon declared',
                'meaning' => 'No favicon link found; tabs/bookmarks look unbranded.',
                'fix' => 'Add a favicon and apple-touch-icon.',
                'owner' => 'Frontend Developer', 'detect' => 'Crawler', 'severity' => 'Low',
            ],

            // ---------------------------------------------------------------
            // WORDPRESS
            // ---------------------------------------------------------------
            'wp_readme_exposed' => [
                'group' => 'WordPress', 'category' => 'WordPress · Hardening',
                'issue' => 'readme.html publicly accessible',
                'meaning' => '/readme.html exposes the exact WordPress version, aiding version-targeted attacks.',
                'fix' => 'Delete or block readme.html via server rules.',
                'owner' => 'WordPress Developer', 'detect' => 'Crawler · /readme.html', 'severity' => 'Low',
            ],
            'wp_xmlrpc_exposed' => [
                'group' => 'WordPress', 'category' => 'WordPress · Hardening',
                'issue' => 'xmlrpc.php enabled',
                'meaning' => 'xmlrpc.php is reachable and is a common vector for brute-force and DDoS amplification.',
                'fix' => 'Disable XML-RPC if unused, or restrict/rate-limit it.',
                'owner' => 'WordPress Developer', 'detect' => 'Crawler · /xmlrpc.php', 'severity' => 'Medium',
            ],
            'wp_users_exposed' => [
                'group' => 'WordPress', 'category' => 'WordPress · Hardening',
                'issue' => 'User enumeration via REST API',
                'meaning' => '/wp-json/wp/v2/users lists author usernames, giving attackers valid login names.',
                'fix' => 'Restrict the users endpoint and block ?author= enumeration.',
                'owner' => 'WordPress Developer', 'detect' => 'Crawler · /wp-json/wp/v2/users', 'severity' => 'Medium',
            ],
            'wp_version_leak' => [
                'group' => 'WordPress', 'category' => 'WordPress · Information Disclosure',
                'issue' => 'WordPress version exposed via generator',
                'meaning' => 'A generator meta tag reveals the WP version, helping attackers match known exploits.',
                'fix' => 'Remove the generator meta tag (remove_action wp_head wp_generator).',
                'owner' => 'WordPress Developer', 'detect' => 'Crawler · View source', 'severity' => 'Low',
            ],

            // ---------------------------------------------------------------
            // DNS / DOMAIN
            // ---------------------------------------------------------------
            'no_www_canonical' => [
                'group' => 'DNS / Domain', 'category' => 'DNS · Canonical Host',
                'issue' => 'www and non-www both resolve without redirect',
                'meaning' => 'Both hostnames serve content independently, creating duplicate-content and split signals.',
                'fix' => 'Pick one canonical host and 301-redirect the other to it.',
                'owner' => 'Network / DNS Admin', 'detect' => 'Crawler · dig', 'severity' => 'Medium',
            ],

            // ---------------------------------------------------------------
            // ANALYTICS / TRACKING
            // ---------------------------------------------------------------
            'no_analytics' => [
                'group' => 'Analytics / Tracking', 'category' => 'Analytics · Coverage',
                'issue' => 'No analytics/tag manager detected',
                'meaning' => 'No Google Analytics, GTM, or common tracking snippet found — you may be flying blind on traffic.',
                'fix' => 'Install analytics (GA4/GTM) if measurement is desired.',
                'owner' => 'SEO Specialist', 'detect' => 'Crawler · Page source', 'severity' => 'Low',
            ],
        ];
    }

    /** Return a single definition by key, or a safe default. */
    public static function get(string $key): array
    {
        $defs = self::defs();
        if (isset($defs[$key])) {
            return $defs[$key];
        }
        return [
            'group' => 'Other', 'category' => 'Uncategorised', 'issue' => $key,
            'meaning' => '', 'fix' => '', 'owner' => 'Developer',
            'detect' => 'Crawler', 'severity' => 'Low',
        ];
    }

    /** Numeric weight per severity, used for the health score. */
    public static function severityWeight(string $sev): int
    {
        switch ($sev) {
            case 'Critical': return 30;
            case 'High':     return 10;
            case 'Medium':   return 4;
            case 'Low':      return 1;
            default:         return 1;
        }
    }
}
