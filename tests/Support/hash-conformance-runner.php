<?php

declare(strict_types=1);

/**
 * Drives ONE Client::hash() call for tests/ClientHashConformanceTest.php.
 *
 * Same process-isolation rationale as tests/Support/conformance-runner.php:
 * src/Pdftract/Client.php declares Jedarden\Pdftract\Client — the same
 * fully-qualified name as the canonical HTTP client — so the file cannot be
 * autoloaded inside the PHPUnit process, where the canonical client's
 * classes are already defined. The wrapper tree's classes are required
 * explicitly here, in a process of their own, before anything asks the
 * autoloader for those names: whichever definition loads first wins for
 * the process.
 *
 * Usage:
 *   php hash-conformance-runner.php <client-file> <binary> <source> <options-json>
 *
 * <binary> need not be a real pdftract — the conformance test also points
 * this runner at throwaway shell scripts that emit canned stdout, to pin
 * the wrapper's own parsing contract without a binary installed.
 *
 * The envelope is a single JSON line on stdout:
 *   {"status": "ok", "result": <hash() return>}
 *   {"status": "exception", "exception": {"class", "message", "exit_code",
 *                                          "timeout_seconds"}}
 * Exit status is 0 whenever an envelope was produced — an exception thrown
 * by the Client is a classified RESULT here, not a runner failure. Non-zero
 * exits (usage errors, fatals) mean the test itself is broken.
 */

use Jedarden\Pdftract\Client;
use Jedarden\Pdftract\PdftractException;
use Jedarden\Pdftract\TimeoutException;

if ($argc !== 5) {
    fwrite(STDERR, "usage: php hash-conformance-runner.php <client-file> <binary> <source> <options-json>\n");
    exit(2);
}

[, $clientFile, $binary, $source, $optionsJson] = $argv;

$repoRoot = dirname(__DIR__, 2);
require $repoRoot . '/vendor/autoload.php';

$clientDir = dirname($clientFile);
require $clientDir . '/PdftractException.php';
require $clientDir . '/TimeoutException.php';
require $clientDir . '/Codegen/ConfigurationException.php';
require $clientFile;

$options = json_decode($optionsJson, true);
if (!is_array($options)) {
    fwrite(STDERR, "options must be a JSON object\n");
    exit(2);
}

// Swallow anything a stray notice prints so stdout stays pure JSON.
ob_start();

try {
    $client = new Client($binary);
    $envelope = ['status' => 'ok', 'result' => $client->hash($source, $options)];
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

echo json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
