<?php

/**
 * Router script for the PHP built-in server, used by concurrency tests.
 *
 * Start with: SLOW_SERVER_COUNTER=/path/to/counter php -S 127.0.0.1:PORT slow-server.php
 * (set PHP_CLI_SERVER_WORKERS for parallel request handling)
 *
 * Every request is appended to the counter file. The response body is fully
 * deterministic: sha256($path) repeated 16 times per chunk (1024 bytes/chunk).
 *
 * Query parameters:
 *   chunks          — number of 1024-byte chunks (default 8)
 *   delay_ms        — sleep between chunks in milliseconds (default 0)
 *   status          — HTTP status code to respond with (default 200)
 *   redirect_to     — send a redirect to this URL; the deterministic body is
 *                     still streamed as a decoy (redirect responses may carry
 *                     a body, and clients must ignore it)
 *   redirect_status — status for the redirect response (default 301)
 *   no_length       — omit Content-Length (the body length is unknown upfront)
 *
 * When the script ends (also when the client aborted the transfer),
 * "<path> <chunks sent>" is appended to "<SLOW_SERVER_COUNTER>.sent".
 *
 * GET /__ready answers with SLOW_SERVER_NONCE (readiness probe; not counted).
 */
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__ready') {
    echo (string) getenv('SLOW_SERVER_NONCE');

    return;
}

$counterFile = getenv('SLOW_SERVER_COUNTER');
if (is_string($counterFile) && $counterFile !== '') {
    file_put_contents(
        $counterFile,
        $_SERVER['REQUEST_METHOD'].' '.$_SERVER['REQUEST_URI']."\n",
        FILE_APPEND | LOCK_EX
    );
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?: '', $query);

$chunks = max(1, (int) ($query['chunks'] ?? 8));
$delayMs = max(0, (int) ($query['delay_ms'] ?? 0));
$status = (int) ($query['status'] ?? 200);

$redirectTo = isset($query['redirect_to']) && is_string($query['redirect_to']) && $query['redirect_to'] !== ''
    ? $query['redirect_to']
    : null;
if ($redirectTo !== null) {
    $status = (int) ($query['redirect_status'] ?? 301);
}

$chunkBody = str_repeat(hash('sha256', $path), 16); // 1024 bytes

http_response_code($status);
if ($redirectTo !== null) {
    header('Location: '.$redirectTo);
}
header('Content-Type: application/octet-stream');
if (! isset($query['no_length'])) {
    header('Content-Length: '.($chunks * strlen($chunkBody)));
}

if ($_SERVER['REQUEST_METHOD'] === 'HEAD' || $status >= 400) {
    return;
}

$sent = 0;
if (is_string($counterFile) && $counterFile !== '') {
    register_shutdown_function(static function () use (&$sent, $counterFile, $path): void {
        file_put_contents($counterFile.'.sent', "{$path} {$sent}\n", FILE_APPEND | LOCK_EX);
    });
}

for ($i = 0; $i < $chunks; $i++) {
    echo $chunkBody;
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
    $sent++;

    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }
}
