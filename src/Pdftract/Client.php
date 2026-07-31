<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

use Jedarden\Pdftract\Codegen\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * pdftract PHP SDK Client
 *
 * Main client for interacting with the pdftract binary.
 * Uses proc_open to spawn subprocesses and parse JSON output.
 *
 * Every subprocess is bounded by a timeout so a hung or pathologically slow
 * pdftract invocation cannot block the calling PHP process indefinitely:
 *
 * - Buffered calls (extract, extractText, extractMarkdown, getMetadata, hash,
 *   classify, verifyReceipt) are bounded by a total wall-clock deadline.
 * - Streaming calls (extractStream, search) are bounded by an *idle* timeout:
 *   the deadline resets every time the child produces output, so a long but
 *   healthy stream is not cut off while a silent stall still is.
 *
 * Timeouts default to self::DEFAULT_TIMEOUT_SECONDS for extract/OCR-class
 * calls and self::DEFAULT_QUICK_TIMEOUT_SECONDS for cheap metadata/hash-class
 * calls, are configurable per client via the constructor, and can be
 * overridden per call with a 'timeout' option (seconds; 0 disables the bound):
 *
 *     $client = new Client('pdftract', null, 600.0, 5.0);
 *     $client->extract('/tmp/big.pdf', ['timeout' => 900]);
 */
class Client
{
    /** Default wall-clock bound for extract/OCR-class calls, in seconds. */
    public const DEFAULT_TIMEOUT_SECONDS = 300.0;

    /** Default wall-clock bound for cheap metadata/hash-class calls, in seconds. */
    public const DEFAULT_QUICK_TIMEOUT_SECONDS = 15.0;

    /** How long a timed-out child gets to exit on SIGTERM before SIGKILL. */
    private const KILL_GRACE_SECONDS = 2.0;

    /** Read chunk size when draining child pipes. */
    private const READ_CHUNK_BYTES = 65536;

    private string $binaryPath;
    private LoggerInterface $logger;
    private float $timeoutSeconds;
    private float $quickTimeoutSeconds;

    /**
     * Constructor
     *
     * @param string $binaryPath Path to pdftract binary (default: 'pdftract')
     * @param LoggerInterface|null $logger PSR-3 logger for debugging (default: null)
     * @param float $timeoutSeconds Timeout for extract/OCR-class calls, in seconds.
     *                              0 disables the bound (default: 300.0)
     * @param float|null $quickTimeoutSeconds Timeout for metadata/hash-class calls, in
     *                                        seconds. Defaults to 15.0, capped at
     *                                        $timeoutSeconds. 0 disables the bound
     * @throws ConfigurationException If a timeout is negative or not finite
     */
    public function __construct(
        string $binaryPath = 'pdftract',
        ?LoggerInterface $logger = null,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?float $quickTimeoutSeconds = null
    ) {
        $this->binaryPath = $binaryPath;
        $this->logger = $logger ?? new NullLogger();
        $this->timeoutSeconds = $this->validateTimeout($timeoutSeconds, 'timeoutSeconds');

        if ($quickTimeoutSeconds === null) {
            // Never let the quick default exceed an explicitly lowered overall timeout.
            $quickTimeoutSeconds = ($timeoutSeconds > 0 && $timeoutSeconds < self::DEFAULT_QUICK_TIMEOUT_SECONDS)
                ? $timeoutSeconds
                : self::DEFAULT_QUICK_TIMEOUT_SECONDS;
        }

        $this->quickTimeoutSeconds = $this->validateTimeout($quickTimeoutSeconds, 'quickTimeoutSeconds');
    }

    /**
     * Get the configured timeout for extract/OCR-class calls
     *
     * @return float Timeout in seconds (0 means unbounded)
     */
    public function getTimeoutSeconds(): float
    {
        return $this->timeoutSeconds;
    }

    /**
     * Get the configured timeout for metadata/hash-class calls
     *
     * @return float Timeout in seconds (0 means unbounded)
     */
    public function getQuickTimeoutSeconds(): float
    {
        return $this->quickTimeoutSeconds;
    }

    /**
     * Validate a timeout value
     *
     * @param float $timeout Timeout in seconds
     * @param string $label Name of the value, for error messages
     * @return float The validated timeout
     * @throws ConfigurationException If the timeout is negative or not finite
     */
    private function validateTimeout(float $timeout, string $label): float
    {
        if (!is_finite($timeout) || $timeout < 0) {
            throw new ConfigurationException(
                sprintf('%s must be a finite, non-negative number of seconds, got: %s', $label, var_export($timeout, true)),
                -1
            );
        }

        return $timeout;
    }

    /**
     * Pop the client-side 'timeout' option out of an options array
     *
     * The value bounds the subprocess and is never forwarded to the CLI.
     *
     * @param array $options Options array, modified in place
     * @param float $default Timeout to use when the option is absent
     * @return float Timeout in seconds
     * @throws ConfigurationException If the option is not a non-negative number
     */
    private function takeTimeout(array &$options, float $default): float
    {
        if (!array_key_exists('timeout', $options)) {
            return $default;
        }

        $value = $options['timeout'];
        unset($options['timeout']);

        if (!is_int($value) && !is_float($value)) {
            throw new ConfigurationException(
                'timeout option must be a number of seconds, got: ' . get_debug_type($value),
                -1
            );
        }

        return $this->validateTimeout((float)$value, 'timeout option');
    }

    /**
     * Build a shell command line for the pdftract binary
     *
     * @param array $args CLI arguments
     * @return string Command line
     */
    private function buildCommand(array $args): string
    {
        $cmd = escapeshellcmd($this->binaryPath);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        return $cmd;
    }

    /**
     * Start a pdftract subprocess
     *
     * @param string $cmd Command line
     * @param array|null $pipes Receives the child's stdout/stderr pipes (stdin is closed)
     * @return resource Process handle
     * @throws PdftractException If the process cannot be started
     */
    private function startProcess(string $cmd, ?array &$pipes)
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            $error = 'Failed to start pdftract process';
            $this->logger->error('Failed to start process', ['command' => $cmd, 'error' => $error]);
            throw new PdftractException($error, -1);
        }

        fclose($pipes[0]);
        unset($pipes[0]);

        // Non-blocking reads so stream_select() alone decides when we wait.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return $process;
    }

    /**
     * Wait until one of the given streams is readable
     *
     * @param array $streams Open streams, keyed by pipe number
     * @param float|null $deadline Absolute deadline (microtime) or null for none
     * @return array|false Ready streams, or false if the deadline expired
     */
    private function selectReadable(array $streams, ?float $deadline): array|false
    {
        while (true) {
            $seconds = null;
            $microseconds = 0;

            if ($deadline !== null) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    return false;
                }
                $seconds = (int)$remaining;
                $microseconds = (int)(($remaining - $seconds) * 1000000);
            }

            $read = array_values($streams);
            $write = null;
            $except = null;

            // Suppressed: stream_select() warns when interrupted by a signal (EINTR).
            $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

            if ($ready === false) {
                // Interrupted (EINTR) — retry, unless the deadline has since passed.
                if ($deadline !== null && microtime(true) >= $deadline) {
                    return false;
                }
                continue;
            }

            if ($ready === 0) {
                if ($deadline === null) {
                    continue;
                }
                return false;
            }

            return $read;
        }
    }

    /**
     * Terminate a child process that overran its timeout
     *
     * Sends SIGTERM, allows a short grace period, then SIGKILL.
     *
     * @param resource $process Process handle
     * @param array $pipes Open pipes to close
     * @return void
     */
    private function terminateProcess($process, array $pipes): void
    {
        @proc_terminate($process, 15);

        $graceDeadline = microtime(true) + self::KILL_GRACE_SECONDS;
        while (microtime(true) < $graceDeadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(10000);
        }

        $status = proc_get_status($process);
        if ($status['running']) {
            @proc_terminate($process, 9);
        }

        $this->closePipes($pipes);
        proc_close($process);
    }

    /**
     * Close any still-open pipes
     *
     * @param array $pipes Pipes
     * @return void
     */
    private function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    /**
     * Reap a child that has closed its pipes, honouring the deadline
     *
     * @param resource $process Process handle
     * @param float|null $deadline Absolute deadline (microtime) or null for none
     * @return int|null Exit code, or null if the child was still running at the deadline
     */
    private function reapProcess($process, ?float $deadline): ?int
    {
        $status = proc_get_status($process);
        while ($status['running']) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                return null;
            }
            usleep(1000);
            $status = proc_get_status($process);
        }

        // Exit code is only valid on the first status read that observed the exit,
        // so use the captured value rather than proc_close()'s return.
        $exitCode = $status['exitcode'];
        proc_close($process);

        return $exitCode;
    }

    /**
     * Raise a timeout error for a command that overran its bound
     *
     * @param string $cmd Command line
     * @param float $timeout Timeout in seconds
     * @param string $stderr Whatever the child wrote to stderr before being killed
     * @return TimeoutException
     */
    private function timeoutError(string $cmd, float $timeout, string $stderr): TimeoutException
    {
        $this->logger->error('pdftract command timed out', [
            'command' => $cmd,
            'timeout' => $timeout,
            'stderr' => $stderr,
        ]);

        $message = sprintf('pdftract command timed out after %s seconds and was terminated: %s', $timeout, $cmd);
        if (trim($stderr) !== '') {
            $message .= ' (stderr: ' . trim($stderr) . ')';
        }

        return new TimeoutException($message, $timeout);
    }

    /**
     * Run a pdftract command to completion, bounded by a wall-clock timeout
     *
     * stdout and stderr are drained concurrently so a child filling one pipe's
     * buffer cannot deadlock the parent. On timeout the child is terminated
     * (SIGTERM, then SIGKILL) and a TimeoutException is thrown.
     *
     * @param array $args CLI arguments
     * @param float $timeout Timeout in seconds (0 for unbounded)
     * @param string $what Human-readable label used in error messages
     * @return string Captured stdout
     * @throws PdftractException On start failure, timeout, or non-zero exit
     */
    private function run(array $args, float $timeout, string $what = 'command'): string
    {
        $cmd = $this->buildCommand($args);

        $this->logger->debug('Executing pdftract ' . $what, ['command' => $cmd, 'timeout' => $timeout]);

        $process = $this->startProcess($cmd, $pipes);
        $deadline = $timeout > 0 ? microtime(true) + $timeout : null;

        $stdout = '';
        $stderr = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        while ($open !== []) {
            $ready = $this->selectReadable($open, $deadline);

            if ($ready === false) {
                $this->terminateProcess($process, $pipes);
                throw $this->timeoutError($cmd, $timeout, $stderr);
            }

            foreach ($ready as $stream) {
                $key = $stream === $pipes[1] ? 1 : 2;
                $chunk = fread($stream, self::READ_CHUNK_BYTES);

                if ($chunk !== false && $chunk !== '') {
                    if ($key === 1) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                    continue;
                }

                if (feof($stream)) {
                    fclose($stream);
                    unset($open[$key]);
                }
            }
        }

        $exitCode = $this->reapProcess($process, $deadline);

        if ($exitCode === null) {
            $this->terminateProcess($process, $pipes);
            throw $this->timeoutError($cmd, $timeout, $stderr);
        }

        if ($exitCode !== 0) {
            $this->logger->error('pdftract ' . $what . ' failed', [
                'command' => $cmd,
                'exit_code' => $exitCode,
                'stderr' => $stderr
            ]);
            throw new PdftractException($stderr ?: 'Command failed with no output', $exitCode);
        }

        return $stdout;
    }

    /**
     * Run a pdftract command that emits NDJSON, yielding each record as it arrives
     *
     * The timeout is an *idle* bound: it resets whenever the child produces
     * output, so a long-running but productive stream is not cut off while a
     * silent stall still is. Abandoning the generator early terminates the child.
     *
     * @param array $args CLI arguments
     * @param float $idleTimeout Seconds of silence tolerated before terminating (0 for unbounded)
     * @param string $what Human-readable label used in error messages
     * @return \Generator Yields decoded JSON records
     * @throws PdftractException On start failure, timeout, or non-zero exit
     */
    private function runStream(array $args, float $idleTimeout, string $what): \Generator
    {
        $cmd = $this->buildCommand($args);

        $this->logger->debug('Executing pdftract ' . $what, ['command' => $cmd, 'idle_timeout' => $idleTimeout]);

        $process = $this->startProcess($cmd, $pipes);
        $finished = false;

        try {
            $buffer = '';
            $stderr = '';
            $open = [1 => $pipes[1], 2 => $pipes[2]];

            while ($open !== []) {
                $deadline = $idleTimeout > 0 ? microtime(true) + $idleTimeout : null;
                $ready = $this->selectReadable($open, $deadline);

                if ($ready === false) {
                    $this->terminateProcess($process, $pipes);
                    $finished = true;
                    throw $this->timeoutError($cmd, $idleTimeout, $stderr);
                }

                foreach ($ready as $stream) {
                    $key = $stream === $pipes[1] ? 1 : 2;
                    $chunk = fread($stream, self::READ_CHUNK_BYTES);

                    if ($chunk === false || $chunk === '') {
                        if (feof($stream)) {
                            fclose($stream);
                            unset($open[$key]);
                        }
                        continue;
                    }

                    if ($key === 2) {
                        $stderr .= $chunk;
                        continue;
                    }

                    $buffer .= $chunk;
                    while (($newline = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $newline);
                        $buffer = substr($buffer, $newline + 1);
                        $record = $this->decodeRecord($line);
                        if ($record !== null) {
                            yield $record;
                        }
                    }
                }
            }

            // Trailing record with no terminating newline.
            $record = $this->decodeRecord($buffer);
            if ($record !== null) {
                yield $record;
            }

            $exitDeadline = $idleTimeout > 0 ? microtime(true) + $idleTimeout : null;
            $exitCode = $this->reapProcess($process, $exitDeadline);
            $finished = true;

            if ($exitCode === null) {
                $this->terminateProcess($process, $pipes);
                throw $this->timeoutError($cmd, $idleTimeout, $stderr);
            }

            if ($exitCode !== 0) {
                $this->logger->error('pdftract ' . $what . ' failed', [
                    'command' => $cmd,
                    'exit_code' => $exitCode,
                    'stderr' => $stderr
                ]);
                throw new PdftractException($stderr ?: 'Command failed with no output', $exitCode);
            }
        } finally {
            // Consumer abandoned the generator (or an error escaped): don't leak the child.
            if (!$finished && is_resource($process)) {
                $this->logger->debug('Terminating abandoned pdftract stream', ['command' => $cmd]);
                $this->terminateProcess($process, $pipes);
            }
        }
    }

    /**
     * Decode one NDJSON line, ignoring blank/undecodable lines
     *
     * @param string $line Raw line
     * @return array|null Decoded record, or null if the line held no record
     */
    private function decodeRecord(string $line): ?array
    {
        if (trim($line) === '') {
            return null;
        }

        $data = json_decode($line, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Execute a pdftract command and return parsed JSON output
     *
     * @param array $args CLI arguments
     * @param float|null $timeout Timeout in seconds (defaults to the quick timeout)
     * @return array Parsed JSON response
     * @throws PdftractException On command failure
     */
    private function exec(array $args, ?float $timeout = null): array
    {
        $stdout = $this->run($args, $timeout ?? $this->quickTimeoutSeconds);

        $result = json_decode($stdout, true);
        if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to decode JSON output', [
                'command' => $this->buildCommand($args),
                'json_error' => json_last_error_msg()
            ]);
            throw new PdftractException('Failed to decode JSON output: ' . json_last_error_msg(), -1);
        }

        return $result;
    }

    /**
     * Build CLI arguments from source and options
     *
     * @param mixed $source Source object or path string
     * @param array $options Options array with camelCase keys
     * @return array CLI arguments
     */
    private function buildArgs($source, array $options = []): array
    {
        $args = [];

        // Handle source
        if ($source instanceof Source) {
            $args = array_merge($args, $source->toArgs());
        } elseif (is_string($source)) {
            $args[] = $source;
        }

        // Handle options - convert camelCase to CLI flags
        foreach ($options as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            $flag = $this->camelToKebab($key);
            $args[] = "--{$flag}";

            if ($value !== true) {
                $args[] = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
            }
        }

        return $args;
    }

    /**
     * Convert camelCase to kebab-case
     *
     * @param string $camel camelCase string
     * @return string kebab-case string
     */
    private function camelToKebab(string $camel): string
    {
        return strtolower(preg_replace('/([A-Z])/', '-$1', lcfirst($camel)));
    }

    /**
     * Extract structured data from a PDF
     *
     * @param mixed $source Source object or path string
     * @param array $options Options (e.g., ['ocrLanguage' => 'eng', 'timeout' => 600])
     * @return array Parsed JSON response with schema_version, metadata, pages
     * @throws PdftractException On command failure or timeout
     */
    public function extract($source, array $options = []): array
    {
        $timeout = $this->takeTimeout($options, $this->timeoutSeconds);
        $args = $this->buildArgs($source, $options);

        return $this->exec($args, $timeout);
    }

    /**
     * Extract plain text from a PDF
     *
     * @param mixed $source Source object or path string
     * @param array $options Options (e.g., ['ocrLanguage' => 'eng', 'timeout' => 600])
     * @return string Plain text content
     * @throws PdftractException On command failure or timeout
     */
    public function extractText($source, array $options = []): string
    {
        $timeout = $this->takeTimeout($options, $this->timeoutSeconds);
        $args = array_merge(['--text'], $this->buildArgs($source, $options));

        return $this->run($args, $timeout);
    }

    /**
     * Extract markdown from a PDF
     *
     * @param mixed $source Source object or path string
     * @param array $options Options (e.g., ['ocrLanguage' => 'eng', 'timeout' => 600])
     * @return string Markdown content
     * @throws PdftractException On command failure or timeout
     */
    public function extractMarkdown($source, array $options = []): string
    {
        $timeout = $this->takeTimeout($options, $this->timeoutSeconds);
        $args = array_merge(['--md'], $this->buildArgs($source, $options));

        return $this->run($args, $timeout);
    }

    /**
     * Extract structured data from a PDF as a stream
     *
     * The 'timeout' option bounds *silence* from the child rather than the
     * total run time — see the class docblock.
     *
     * @param mixed $source Source object or path string
     * @param array $options Options (e.g., ['ocrLanguage' => 'eng', 'timeout' => 60])
     * @return \Generator Yields parsed JSON objects one at a time
     * @throws PdftractException On command failure or timeout
     */
    public function extractStream($source, array $options = []): \Generator
    {
        $timeout = $this->takeTimeout($options, $this->timeoutSeconds);
        $args = $this->buildArgs($source, $options);

        yield from $this->runStream($args, $timeout, 'stream command');
    }

    /**
     * Search for text patterns in a PDF
     *
     * The 'timeout' option bounds *silence* from the child rather than the
     * total run time — see the class docblock.
     *
     * @param mixed $source Source object or path string
     * @param string $pattern Search pattern (supports regex)
     * @param array $options Options (e.g., ['caseInsensitive' => true, 'timeout' => 60])
     * @return \Generator Yields search matches one at a time
     * @throws PdftractException On command failure or timeout
     */
    public function search($source, string $pattern, array $options = []): \Generator
    {
        $timeout = $this->takeTimeout($options, $this->timeoutSeconds);
        $args = array_merge(['grep', $pattern], $this->buildArgs($source, $options));

        yield from $this->runStream($args, $timeout, 'search command');
    }

    /**
     * Get metadata from a PDF
     *
     * @param mixed $source Source object or path string
     * @param array $options Options (e.g., ['timeout' => 5])
     * @return array Metadata with page_count, dimensions, etc.
     * @throws PdftractException On command failure or timeout
     */
    public function getMetadata($source, array $options = []): array
    {
        $timeout = $this->takeTimeout($options, $this->quickTimeoutSeconds);
        $args = array_merge(['--metadata-only'], $this->buildArgs($source, $options));

        return $this->exec($args, $timeout);
    }

    /**
     * Compute hash of a PDF
     *
     * @param mixed $source Source object or path string
     * @param array $options Options (e.g., ['fast' => true, 'timeout' => 5])
     * @return array Hash data with 'hash' and 'fast_hash' keys
     * @throws PdftractException On command failure or timeout
     */
    public function hash($source, array $options = []): array
    {
        $timeout = $this->takeTimeout($options, $this->quickTimeoutSeconds);
        $args = array_merge(['hash'], $this->buildArgs($source, $options));

        return $this->exec($args, $timeout);
    }

    /**
     * Classify a PDF document
     *
     * @param mixed $source Source object or path string
     * @param array $options Options (e.g., ['timeout' => 5])
     * @return array Classification data with document type and confidence
     * @throws PdftractException On command failure or timeout
     */
    public function classify($source, array $options = []): array
    {
        $timeout = $this->takeTimeout($options, $this->quickTimeoutSeconds);
        $args = array_merge(['classify'], $this->buildArgs($source, $options));

        return $this->exec($args, $timeout);
    }

    /**
     * Verify a processing receipt
     *
     * @param string $path Path to PDF file
     * @param string $receipt Receipt string to verify
     * @param float|null $timeout Timeout in seconds (defaults to the quick timeout)
     * @return bool True if receipt is valid, false otherwise
     * @throws PdftractException On command failure or timeout
     */
    public function verifyReceipt(string $path, string $receipt, ?float $timeout = null): bool
    {
        $args = ['verify-receipt', $path, $receipt];
        $timeout = $timeout === null
            ? $this->quickTimeoutSeconds
            : $this->validateTimeout($timeout, 'timeout');

        $stdout = $this->run($args, $timeout, 'verify-receipt command');

        return trim($stdout) === 'true';
    }
}
