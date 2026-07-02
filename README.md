# SiteScope — Website Error & Health Scanner (PHP)

Enter any website URL and SiteScope crawls it **server-side with PHP + cURL** (no
browser CORS limits) and reports every automatically-detectable issue — broken
links, redirects, SSL/TLS, security headers, SEO, performance, accessibility,
WordPress hardening, analytics — each mapped to a **severity** and the
**developer who owns the fix**, exactly like the issue catalog spreadsheet.

Think "Search Console-style URL box", but covering ~40 check types across the
whole site in one beautiful dashboard.

---

## Quick start (2 minutes)

You need **PHP 7.4+** with the `curl`, `dom`/`xml` and `mbstring` extensions
(standard on almost every host and in XAMPP/MAMP).

### Option A — run locally with PHP's built-in server
```bash
cd website-error-scanner
php -S localhost:8000
```
Then open **http://localhost:8000/** in your browser, type a URL (e.g.
`https://www.steelandstud.com/`) and click **Scan site**.

### Option B — drop it on your hosting
Upload the `website-error-scanner` folder to any PHP host (cPanel, shared
hosting, VPS) and visit `https://yourdomain.com/website-error-scanner/`.

That's it — no database, no build step, no Composer.

---

## What it checks

| Group | Examples |
|---|---|
| **HTTP / Status** | 4xx / 5xx pages, broken internal & external links, redirect chains, temporary (302) redirects, meta-refresh |
| **SSL / TLS** | HTTPS availability, HTTP→HTTPS redirect, certificate expiry/soon-to-expire, hostname mismatch, mixed content |
| **Security / Headers** | Missing HSTS, CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, server/version disclosure |
| **SEO** | Missing/duplicate title & meta description, length checks, H1, canonical, noindex, robots.txt, XML sitemap, Open Graph, structured data (JSON-LD), thin content |
| **Performance / Speed** | No gzip/brotli, missing cache headers, slow TTFB, heavy HTML, too many assets, render-blocking scripts |
| **UI / UX / Accessibility** | Missing alt text, unlabelled form fields, no viewport, missing `lang`, skipped heading levels, empty links, no favicon |
| **WordPress** | Exposed `readme.html`, `xmlrpc.php` enabled, REST user enumeration, version leak via generator |
| **DNS / Domain** | www vs non-www both resolving without a canonical redirect |
| **Analytics** | No analytics/tag-manager snippet detected |

Every finding shows: **what it is, why it matters, how to fix it, the owner, and
where it was found** — and can be exported to **CSV** or **JSON**.

---

## Using the dashboard

1. **Dashboard** — health score (0–100 + letter grade), severity cards, issues-by-group bar chart, severity doughnut, and the top priorities.
2. **All Issues** — full filterable/searchable table (by severity, group, owner) with CSV/JSON export.
3. **By Owner** — issues grouped by the responsible role, ready to hand to your team.
4. **Catalog** — every check that fired, mapped to its catalog category and fix.

Scan options (⚙ button): **max pages** to crawl, request **timeout**, and which
**categories** to include.

---

## How it works

```
index.php            Dashboard UI (HTML/CSS/JS, Chart.js)
api/scan.php         Scan endpoint — streams live progress via Server-Sent Events
lib/Scanner.php      cURL client + breadth-first crawler + orchestration
lib/Checks.php       Turns responses + parsed HTML into catalog-mapped findings
lib/Catalog.php      Every check's Group/Category/Meaning/Fix/Owner/Severity
lib/helpers.php      URL normalisation, header parsing, byte formatting
assets/style.css     Dashboard styling
assets/app.js        Front-end logic, charts, filters, exports
```

The crawler fetches the start URL, follows internal links breadth-first up to
your page cap, runs per-page checks, then sweeps discovered links for breakage
and compares pages for duplicate titles/descriptions. Progress streams to the
browser live as each page is crawled.

---

## Honest limitations

A crawler sees a site the way a visitor and a search engine do. Some catalog
rows **cannot** be detected from the outside without server access or
credentials, so SiteScope intentionally does **not** guess at them:

- Malware/database/email-deliverability/cPanel internals (need server access)
- JavaScript-rendered content that only appears after client-side execution
  (SiteScope reads the delivered HTML, like Googlebot's first pass)
- Core Web Vitals field data (needs real-user or Lighthouse lab data)

Everything SiteScope reports is based on the **actual HTTP responses and HTML**
of your site, so there are no invented issues.

---

## Notes

- Scanning of `localhost` and private IP ranges is disabled for safety.
- Be considerate: only scan sites you own or have permission to test.
- Default cap: 50 pages / 200 link checks per scan (adjustable in the UI).
