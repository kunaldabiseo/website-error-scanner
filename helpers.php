<?php
/**
 * helpers.php — small stateless utilities used across the scanner.
 */

/** Normalise a user-entered URL: add scheme, lowercase host, strip fragment. */
function normalize_url(string $url, ?string $base = null): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    // Resolve relative URLs against a base.
    if ($base !== null && !preg_match('#^https?://#i', $url)) {
        $url = resolve_relative($base, $url);
        if ($url === null) {
            return null;
        }
    }

    // Add scheme if the user typed "example.com".
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    $p = parse_url($url);
    if ($p === false || empty($p['host'])) {
        return null;
    }

    $scheme = strtolower($p['scheme'] ?? 'https');
    $host   = strtolower($p['host']);
    $port   = isset($p['port']) ? ':' . $p['port'] : '';
    $path   = $p['path'] ?? '/';
    if ($path === '') {
        $path = '/';
    }
    $query  = isset($p['query']) ? '?' . $p['query'] : '';

    // Drop the fragment; it never reaches the server.
    return $scheme . '://' . $host . $port . $path . $query;
}

/** Resolve a relative URL against an absolute base URL. */
function resolve_relative(string $base, string $rel): ?string
{
    if ($rel === '' || $rel[0] === '#') {
        return null;
    }
    if (preg_match('/^(mailto:|tel:|javascript:|data:|#)/i', $rel)) {
        return null;
    }
    if (preg_match('#^https?://#i', $rel)) {
        return $rel;
    }
    if (strpos($rel, '//') === 0) {
        $bp = parse_url($base);
        return ($bp['scheme'] ?? 'https') . ':' . $rel;
    }

    $bp = parse_url($base);
    if ($bp === false || empty($bp['host'])) {
        return null;
    }
    $scheme = $bp['scheme'] ?? 'https';
    $host   = $bp['host'];
    $port   = isset($bp['port']) ? ':' . $bp['port'] : '';
    $basePath = $bp['path'] ?? '/';

    if ($rel[0] === '/') {
        $path = $rel;
    } else {
        $dir = preg_replace('#/[^/]*$#', '/', $basePath);
        if ($dir === '' || $dir === null) {
            $dir = '/';
        }
        $path = $dir . $rel;
    }

    // Collapse ../ and ./ segments.
    $segments = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($segments);
        } else {
            $segments[] = $seg;
        }
    }
    $normPath = '/' . implode('/', $segments);
    // Preserve trailing slash intent.
    if (substr($path, -1) === '/' && substr($normPath, -1) !== '/') {
        $normPath .= '/';
    }

    // Keep any query string on the relative reference.
    $q = '';
    if (($qpos = strpos($rel, '?')) !== false) {
        $q = substr($rel, $qpos);
        $normPath = preg_replace('/\?.*$/', '', $normPath);
    }

    return $scheme . '://' . $host . $port . $normPath . $q;
}

/** Registrable host without a leading "www.". */
function root_host(string $host): string
{
    return preg_replace('/^www\./i', '', strtolower($host));
}

/** True if two URLs share the same registrable host (www-insensitive). */
function same_site(string $a, string $b): bool
{
    $ha = parse_url($a, PHP_URL_HOST);
    $hb = parse_url($b, PHP_URL_HOST);
    if (!$ha || !$hb) {
        return false;
    }
    return root_host($ha) === root_host($hb);
}

/** Human-readable byte size. */
function human_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

/** Parse a raw HTTP header block into a lowercased assoc array (last wins, keeps arrays for repeats). */
function parse_headers_block(string $raw): array
{
    $headers = [];
    $lines = preg_split('/\r\n|\n|\r/', $raw);
    foreach ($lines as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $name = strtolower(trim($name));
        $value = trim($value);
        if (isset($headers[$name])) {
            if (is_array($headers[$name])) {
                $headers[$name][] = $value;
            } else {
                $headers[$name] = [$headers[$name], $value];
            }
        } else {
            $headers[$name] = $value;
        }
    }
    return $headers;
}

/** Flatten a possibly-array header value to a single string. */
function header_str($value): string
{
    if (is_array($value)) {
        return implode(', ', $value);
    }
    return (string) $value;
}
