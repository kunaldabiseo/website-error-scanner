<?php
/**
 * api/scan.php — scan endpoint.
 *
 * Modes:
 *   ?url=...&stream=1   -> Server-Sent Events: live progress + final "result" event
 *   ?url=...            -> single JSON response (no streaming)
 *
 * Extra params: max_pages (1-200), timeout (5-30), cats (comma list:
 *   seo,technical,performance,accessibility,analytics)
 */

require_once __DIR__ . '/Scanner.php';

// -- Guard: cURL is required -------------------------------------------------
if (!function_exists('curl_init')) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'PHP cURL extension is not enabled. Enable ext-curl and reload.']);
    exit;
}

@set_time_limit(300);
ignore_user_abort(false);

$rawUrl = $_GET['url'] ?? $_POST['url'] ?? '';
$stream = ($_GET['stream'] ?? '') === '1';

$url = normalize_url((string) $rawUrl);
if ($url === null) {
    if ($stream) { sse_headers(); sse_send('error', ['message' => 'Please enter a valid website URL.']); }
    else { json_headers(); http_response_code(400); echo json_encode(['error' => 'Please enter a valid website URL.']); }
    exit;
}

// Block obvious SSRF targets (localhost / private ranges).
$host = parse_url($url, PHP_URL_HOST);
$ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
if (is_private_ip($ip) || in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
    $msg = 'Scanning localhost / private addresses is disabled for safety.';
    if ($stream) { sse_headers(); sse_send('error', ['message' => $msg]); }
    else { json_headers(); http_response_code(400); echo json_encode(['error' => $msg]); }
    exit;
}

$opts = [
    'max_pages'  => (int) ($_GET['max_pages'] ?? 50),
    'timeout'    => (int) ($_GET['timeout'] ?? 20),
    'categories' => array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['cats'] ?? ''))))),
];

if ($stream) {
    sse_headers();
    $scanner = new Scanner($url, $opts, function (string $type, array $data) {
        sse_send($type, $data);
    });
    try {
        $result = $scanner->run();
        sse_send('result', $result);
    } catch (Throwable $e) {
        sse_send('error', ['message' => 'Scan failed: ' . $e->getMessage()]);
    }
    sse_send('end', []);
    exit;
}

// Non-streaming JSON mode.
json_headers();
$scanner = new Scanner($url, $opts, null);
try {
    echo json_encode($scanner->run(), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Scan failed: ' . $e->getMessage()]);
}

// --------------------------------------------------------------------------
function json_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

function sse_headers(): void
{
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');   // disable nginx buffering
    header('Connection: keep-alive');
    while (ob_get_level() > 0) { ob_end_flush(); }
    echo str_pad('', 2048) . "\n";     // prime some proxies
    @ob_flush(); @flush();
}

function sse_send(string $event, array $data): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush(); @flush();
}

function is_private_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    return !filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
}
