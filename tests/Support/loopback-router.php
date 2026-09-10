<?php

declare(strict_types=1);

/**
 * Router script for the LoopbackServer fixture harness (Support/LoopbackServer.php)
 *
 * This file runs inside the PHP built-in cli-server that LoopbackServer
 * spawns, and answers every request in three steps:
 *
 * 1. Record the request (method, path, query, headers, multipart fields and
 *    uploads, raw body digest) to a JSONL log the test reads back — before
 *    anything is written to the response, so even a request that never gets
 *    a response is still recorded.
 * 2. Pick the script for this request: the index-th ScriptedResponse queued
 *    for this exact method + path, where the index is how many requests to
 *    that route came before. The index lives in its own counter file rather
 *    than being derived from the log, so LoopbackServer::clearRequests()
 *    cannot change which script a request would get.
 * 3. Answer with it: status, headers, then either a single body (with an
 *    explicit Content-Length) or, in streaming mode, chunks written and
 *    flushed one at a time with a delay after each — a client reading
 *    incrementally sees the gaps. A stalled script sleeps out its full
 *    stall before answering, which is what exercises a client timeout.
 *
 * The cli-server re-includes this file for every request in one long-lived
 * process, so nothing here may rely on fresh global state between requests,
 * and every helper declared must be wrapped in function_exists() to survive
 * being included more than once.
 *
 * Configuration arrives by environment (set by LoopbackServer at spawn):
 *
 * - PDFTRACT_LOOPBACK_SPEC     JSON: {"scripts": [ScriptedResponse::toArray(), ...]}
 *                              rewritten by LoopbackServer::enqueue(), read
 *                              fresh on every request
 * - PDFTRACT_LOOPBACK_RECORD   JSONL request log, appended under an exclusive
 *                              lock, read by LoopbackServer::requests()
 * - PDFTRACT_LOOPBACK_CONSUMED JSON: {"METHOD path": <occurrences>} script
 *                              consumption counters
 */

if (!function_exists('loopback_normalize_upload')) {
    /**
     * Normalize one $_FILES entry into a list of single-upload descriptors
     *
     * PHP collapses multiple uploads under one field name into arrays of
     * per-attribute arrays, so both the flat (one upload) and nested (many)
     * shapes have to fold into the same list-of-uploads shape.
     *
     * @param array<string, mixed> $file One $_FILES entry
     * @return list<array{filename: string, mime: string, size: int, sha256: string|null, error: int}>
     */
    function loopback_normalize_upload(array $file): array
    {
        $names = $file['name'] ?? [];

        if (!is_array($names)) {
            return [loopback_describe_upload(
                (string)$names,
                (string)($file['type'] ?? ''),
                (int)($file['size'] ?? 0),
                $file['tmp_name'] ?? null,
                (int)($file['error'] ?? UPLOAD_ERR_OK),
            )];
        }

        $uploads = [];

        foreach (array_keys($names) as $index) {
            $uploads[] = loopback_describe_upload(
                (string)$names[$index],
                (string)($file['type'][$index] ?? ''),
                (int)($file['size'][$index] ?? 0),
                $file['tmp_name'][$index] ?? null,
                (int)($file['error'][$index] ?? UPLOAD_ERR_OK),
            );
        }

        return $uploads;
    }

    /**
     * Describe one upload, hashing its content while the temp file exists
     *
     * @param mixed $tmpName The uploaded temp file path, if any
     * @return array{filename: string, mime: string, size: int, sha256: string|null, error: int}
     */
    function loopback_describe_upload(string $filename, string $mime, int $size, mixed $tmpName, int $error): array
    {
        $sha256 = null;

        if (is_string($tmpName) && $error === UPLOAD_ERR_OK && is_file($tmpName) && is_readable($tmpName)) {
            $sha256 = hash_file('sha256', $tmpName) ?: null;
        }

        return ['filename' => $filename, 'mime' => $mime, 'size' => $size, 'sha256' => $sha256, 'error' => $error];
    }
}

// A client disconnecting mid-response (which is exactly what a timed-out
// request does) must not abort the script before it finishes logging.
ignore_user_abort(true);

$specPath = getenv('PDFTRACT_LOOPBACK_SPEC');
$recordPath = getenv('PDFTRACT_LOOPBACK_RECORD');
$consumedPath = getenv('PDFTRACT_LOOPBACK_CONSUMED');

if (!is_string($specPath) || !is_string($recordPath) || !is_string($consumedPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'loopback_misconfigured', 'message' => 'the loopback router is missing its environment']);

    return;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
$queryString = (string)(parse_url($uri, PHP_URL_QUERY) ?: '');

$headers = [];

foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string)$key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string)$key, 5)))] = (string)$value;
    }
}

// CONTENT_* and AUTHORIZATION do not always arrive with an HTTP_ prefix.
foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length', 'AUTHORIZATION' => 'authorization'] as $key => $name) {
    if (isset($_SERVER[$key])) {
        $headers[$name] = (string)$_SERVER[$key];
    }
}

$uploads = [];

foreach ($_FILES as $field => $file) {
    if (is_array($file)) {
        $uploads[(string)$field] = loopback_normalize_upload($file);
    }
}

$rawBody = (string)file_get_contents('php://input');

$record = [
    'method' => $method,
    'path' => $path,
    'query_string' => $queryString,
    'query' => $_GET,
    'headers' => $headers,
    'fields' => array_map(strval(...), $_POST),
    'files' => $uploads,
    'raw_body_bytes' => strlen($rawBody),
    'raw_body_sha256' => $rawBody === '' ? null : hash('sha256', $rawBody),
];

// Logged before anything else, under a lock so the log stays line-atomic
// even if a test ever runs the server with more than one worker.
file_put_contents(
    $recordPath,
    json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n",
    FILE_APPEND | LOCK_EX,
);

// Consume the next script queued for this exact method + path.
$scripts = [];

if (is_file($specPath)) {
    $spec = json_decode((string)file_get_contents($specPath), true);
    $scripts = is_array($spec['scripts'] ?? null) ? $spec['scripts'] : [];
}

$counterKey = $method . ' ' . $path;
$consumed = [];

if (is_file($consumedPath)) {
    $decoded = json_decode((string)file_get_contents($consumedPath), true);
    $consumed = is_array($decoded) ? $decoded : [];
}

$occurrence = (int)($consumed[$counterKey] ?? 0);
$consumed[$counterKey] = $occurrence + 1;
file_put_contents($consumedPath, json_encode($consumed, JSON_UNESCAPED_SLASHES), LOCK_EX);

$script = null;
$seen = 0;

foreach ($scripts as $candidate) {
    if (!is_array($candidate)) {
        continue;
    }

    $candidateMethod = strtoupper((string)($candidate['method'] ?? 'POST'));
    $candidatePath = (string)($candidate['path'] ?? '');

    if ($candidateMethod !== $method || $candidatePath !== $path) {
        continue;
    }

    if ($seen === $occurrence) {
        $script = $candidate;
        break;
    }

    $seen++;
}

if ($script === null) {
    // No plausible stub: an unscripted request must be loud, or a test
    // asserting on the wrong route would silently pass.
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'loopback_no_script',
        'message' => sprintf('no script queued for %s %s (occurrence %d)', $method, $path, $occurrence),
    ], JSON_UNESCAPED_SLASHES);

    return;
}

$delay = (float)($script['delay_seconds'] ?? 0.0);

if ($delay > 0) {
    usleep((int)round($delay * 1_000_000));
}

if ((bool)($script['stall'] ?? false)) {
    usleep((int)round((float)($script['stall_seconds'] ?? 5.0) * 1_000_000));
}

http_response_code((int)($script['status'] ?? 200));

$scriptHeaders = is_array($script['headers'] ?? null) ? $script['headers'] : [];

foreach ($scriptHeaders as $name => $value) {
    header((string)$name . ': ' . (string)$value);
}

if (!isset($scriptHeaders['Content-Type'])) {
    header('Content-Type: text/plain; charset=UTF-8');
}

$chunks = $script['chunks'] ?? null;

if (is_array($chunks)) {
    // Streaming: no Content-Length, so the connection close frames the body
    // and each chunk reaches the client as it is written.
    foreach ($chunks as $chunk) {
        $body = is_array($chunk) ? (string)($chunk['body'] ?? '') : (string)$chunk;
        echo $body;

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();

        $chunkDelay = is_array($chunk) ? (float)($chunk['delaySeconds'] ?? 0.0) : 0.0;

        if ($chunkDelay > 0) {
            usleep((int)round($chunkDelay * 1_000_000));
        }
    }

    return;
}

$body = (string)($script['body'] ?? '');
header('Content-Length: ' . strlen($body));
echo $body;
