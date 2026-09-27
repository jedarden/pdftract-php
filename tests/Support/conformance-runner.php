<?php

declare(strict_types=1);

/**
 * Drives ONE Jedarden\Pdftract\Client call against a real pdftract binary.
 *
 * This is the process-isolation half of the binary conformance harness
 * (tests/ClientBinaryConformanceTest.php). The harness hands it a method
 * name and a fully-resolved argument payload; it loads the CLI-subprocess
 * Client, invokes the method, and prints a JSON envelope describing what
 * happened. One case, one process, so a hung or wedged binary cannot take
 * the PHPUnit process down with it and so the harness can classify the
 * outcome instead of dying on it. The hash pins' mutation check uses this
 * same path: the negative-proof runs re-execute the suite against a mutated
 * Client and read these exception envelopes for clap's parse markers.
 *
 * Why the direct requires below: this repo carries two classes with the
 * SAME fully-qualified names. Composer's PSR-4 maps Jedarden\Pdftract\ to
 * src/, which resolves Jedarden\Pdftract\Client (and PdftractException,
 * TimeoutException, Source) to the canonical HTTP client under src/. The
 * CLI-subprocess client this harness exercises lives at
 * src/Pdftract/Client.php — a path no autoloader rule points at. Requiring
 * the subprocess tree's files explicitly, before anything asks the
 * autoloader for those names, defines the classes first and the autoloader
 * never fires: whichever definition loads first wins for the process.
 * That is also why this file must stay a standalone script — in the PHPUnit
 * process the canonical client is already loaded by other tests.
 *
 * Usage:
 *   php conformance-runner.php <client-file> <binary> <payload-json>
 *
 * <payload-json> is everything the runner needs to make ONE call:
 *   {"method": "hash", "source": "/abs/fixture.pdf", "options": {"timeout": 30},
 *    "pattern": "...", "receipt": "..."}
 * Only `method` and `source` are required; `pattern`/`receipt` feed the
 * search()/verifyReceipt() positionals that are not part of the options
 * array. Options are the Client's documented camelCase keys (for hash()
 * that is 'timeout' and 'password' — the retired docblock's 'fast' option
 * never existed upstream, so it must not appear in examples either).
 *
 * The envelope is a single JSON line on stdout:
 *   {"status": "ok", "result": <method return, generator calls consumed>}
 *   {"status": "exception", "exception": {"class", "message", "exit_code",
 *                                          "timeout_seconds"}}
 * Exit status is 0 whenever an envelope was produced — an exception thrown
 * by the Client is a classified RESULT here, not a runner failure. Non-zero
 * exits (usage errors, fatals) mean the harness itself is broken; the test
 * side reports those as harness errors rather than conformance outcomes.
 */

use Jedarden\Pdftract\Client;
use Jedarden\Pdftract\PdftractException;
use Jedarden\Pdftract\TimeoutException;

if ($argc !== 4) {
    fwrite(STDERR, "usage: php conformance-runner.php <client-file> <binary> <payload-json>\n");
    exit(2);
}

[, $clientFile, $binary, $payloadJson] = $argv;

$repoRoot = dirname(__DIR__, 2);
require $repoRoot . '/vendor/autoload.php';

$clientDir = dirname($clientFile);
require $clientDir . '/PdftractException.php';
require $clientDir . '/TimeoutException.php';
require $clientDir . '/Codegen/ConfigurationException.php';
require $clientFile;

$payload = json_decode($payloadJson, true);
if (!is_array($payload) || !isset($payload['method'], $payload['source'])) {
    fwrite(STDERR, "payload must be an object with 'method' and 'source'" . PHP_EOL);
    exit(2);
}

$method = (string) $payload['method'];
$source = (string) $payload['source'];
$options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
$pattern = isset($payload['pattern']) ? (string) $payload['pattern'] : null;
$receipt = isset($payload['receipt']) ? (string) $payload['receipt'] : null;

/**
 * Consume a generator fully (a half-consumed stream leaves the child
 * running) and return the collected records.
 */
function drain(\Generator $stream): array
{
    $records = [];
    foreach ($stream as $record) {
        $records[] = $record;
    }

    return $records;
}

// Swallow anything a stray notice prints so stdout stays pure JSON.
ob_start();

try {
    $client = new Client($binary);

    $result = match ($method) {
        'extract' => $client->extract($source, $options),
        'extract_text' => $client->extractText($source, $options),
        'extract_markdown' => $client->extractMarkdown($source, $options),
        'extract_stream' => drain($client->extractStream($source, $options)),
        'search' => drain($client->search($source, (string) $pattern, $options)),
        'get_metadata' => $client->getMetadata($source, $options),
        'hash' => $client->hash($source, $options),
        'classify' => $client->classify($source, $options),
        'verify_receipt' => $client->verifyReceipt($source, (string) $receipt),
        default => throw new InvalidArgumentException("unsupported method: {$method}"),
    };

    $envelope = ['status' => 'ok', 'result' => $result];
} catch (\Throwable $e) {
    $envelope = [
        'status' => 'exception',
        'exception' => [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'exit_code' => $e instanceof PdftractException ? $e->getExitCode() : null,
            'timeout_seconds' => $e instanceof TimeoutException ? $e->getTimeoutSeconds() : null,
        ],
    ];
} finally {
    ob_end_clean();
}

// Results are the Client's plain return values (arrays, strings, bools,
// arrays of decoded records) — always JSON-encodable in practice. Should a
// future return value break that, describe it rather than dying en route.
$encoded = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    $encoded = json_encode([
        'status' => 'exception',
        'exception' => [
            'class' => 'JsonEncodeFailure',
            'message' => json_last_error_msg(),
            'exit_code' => null,
            'timeout_seconds' => null,
        ],
    ]);
}

echo $encoded, PHP_EOL;
