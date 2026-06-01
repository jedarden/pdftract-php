<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * pdftract PHP SDK Client
 *
 * Main client for interacting with the pdftract binary.
 * Uses proc_open to spawn subprocesses and parse JSON output.
 */
class Client
{
    private string $binaryPath;
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param string $binaryPath Path to pdftract binary (default: 'pdftract')
     * @param LoggerInterface|null $logger PSR-3 logger for debugging (default: null)
     */
    public function __construct(
        string $binaryPath = 'pdftract',
        ?LoggerInterface $logger = null
    ) {
        $this->binaryPath = $binaryPath;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Execute a pdftract command and return parsed JSON output
     *
     * @param array $args CLI arguments
     * @return array Parsed JSON response
     * @throws PdftractException On command failure
     */
    private function exec(array $args): array
    {
        $cmd = escapeshellcmd($this->binaryPath);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $this->logger->debug('Executing pdftract command', ['command' => $cmd]);

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

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->logger->error('pdftract command failed', [
                'command' => $cmd,
                'exit_code' => $exitCode,
                'stderr' => $stderr
            ]);
            throw new PdftractException($stderr ?: 'Command failed with no output', $exitCode);
        }

        $result = json_decode($stdout, true);
        if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to decode JSON output', [
                'command' => $cmd,
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
     * @param array $options Options (e.g., ['ocrLanguage' => 'eng'])
     * @return array Parsed JSON response with schema_version, metadata, pages
     * @throws PdftractException On command failure
     */
    public function extract($source, array $options = []): array
    {
        $args = $this->buildArgs($source, $options);
        return $this->exec($args);
    }

    /**
     * Extract plain text from a PDF
     *
     * @param mixed $source Source object or path string
     * @param array $options Options (e.g., ['ocrLanguage' => 'eng'])
     * @return string Plain text content
     * @throws PdftractException On command failure
     */
    public function extractText($source, array $options = []): string
    {
        $args = array_merge(['--text'], $this->buildArgs($source, $options));

        $cmd = escapeshellcmd($this->binaryPath);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $this->logger->debug('Executing pdftract command', ['command' => $cmd]);

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

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->logger->error('pdftract command failed', [
                'command' => $cmd,
                'exit_code' => $exitCode,
                'stderr' => $stderr
            ]);
            throw new PdftractException($stderr ?: 'Command failed with no output', $exitCode);
        }

        return $stdout;
    }

    /**
     * Extract markdown from a PDF
     *
     * @param mixed $source Source object or path string
     * @param array $options Options (e.g., ['ocrLanguage' => 'eng'])
     * @return string Markdown content
     * @throws PdftractException On command failure
     */
    public function extractMarkdown($source, array $options = []): string
    {
        $args = array_merge(['--md'], $this->buildArgs($source, $options));

        $cmd = escapeshellcmd($this->binaryPath);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $this->logger->debug('Executing pdftract command', ['command' => $cmd]);

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

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->logger->error('pdftract command failed', [
                'command' => $cmd,
                'exit_code' => $exitCode,
                'stderr' => $stderr
            ]);
            throw new PdftractException($stderr ?: 'Command failed with no output', $exitCode);
        }

        return $stdout;
    }

    /**
     * Extract structured data from a PDF as a stream
     *
     * @param mixed $source Source object or path string
     * @param array $options Options (e.g., ['ocrLanguage' => 'eng'])
     * @return \Generator Yields parsed JSON objects one at a time
     * @throws PdftractException On command failure
     */
    public function extractStream($source, array $options = []): \Generator
    {
        $args = $this->buildArgs($source, $options);

        $cmd = escapeshellcmd($this->binaryPath);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $this->logger->debug('Executing pdftract stream command', ['command' => $cmd]);

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            $error = 'Failed to start pdftract process';
            $this->logger->error('Failed to start stream process', ['command' => $cmd, 'error' => $error]);
            throw new PdftractException($error, -1);
        }

        fclose($pipes[0]);

        while (!feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line === false || trim($line) === '') {
                continue;
            }

            $data = json_decode($line, true);
            if ($data !== null) {
                yield $data;
            }
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->logger->error('pdftract stream command failed', [
                'command' => $cmd,
                'exit_code' => $exitCode,
                'stderr' => $stderr
            ]);
            throw new PdftractException($stderr ?: 'Stream command failed with no output', $exitCode);
        }
    }

    /**
     * Search for text patterns in a PDF
     *
     * @param mixed $source Source object or path string
     * @param string $pattern Search pattern (supports regex)
     * @param array $options Options (e.g., ['caseInsensitive' => true])
     * @return \Generator Yields search matches one at a time
     * @throws PdftractException On command failure
     */
    public function search($source, string $pattern, array $options = []): \Generator
    {
        $args = array_merge(['grep', $pattern], $this->buildArgs($source, $options));

        $cmd = escapeshellcmd($this->binaryPath);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $this->logger->debug('Executing pdftract search command', ['command' => $cmd]);

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            $error = 'Failed to start pdftract process';
            $this->logger->error('Failed to start search process', ['command' => $cmd, 'error' => $error]);
            throw new PdftractException($error, -1);
        }

        fclose($pipes[0]);

        while (!feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line === false || trim($line) === '') {
                continue;
            }

            $data = json_decode($line, true);
            if ($data !== null) {
                yield $data;
            }
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->logger->error('pdftract search command failed', [
                'command' => $cmd,
                'exit_code' => $exitCode,
                'stderr' => $stderr
            ]);
            throw new PdftractException($stderr ?: 'Search command failed with no output', $exitCode);
        }
    }

    /**
     * Get metadata from a PDF
     *
     * @param mixed $source Source object or path string
     * @param array $options Options
     * @return array Metadata with page_count, dimensions, etc.
     * @throws PdftractException On command failure
     */
    public function getMetadata($source, array $options = []): array
    {
        $args = array_merge(['--metadata-only'], $this->buildArgs($source, $options));
        return $this->exec($args);
    }

    /**
     * Compute hash of a PDF
     *
     * @param mixed $source Source object or path string
     * @param array $options Options (e.g., ['fast' => true])
     * @return array Hash data with 'hash' and 'fast_hash' keys
     * @throws PdftractException On command failure
     */
    public function hash($source, array $options = []): array
    {
        $args = array_merge(['hash'], $this->buildArgs($source, $options));
        return $this->exec($args);
    }

    /**
     * Classify a PDF document
     *
     * @param mixed $source Source object or path string
     * @param array $options Options
     * @return array Classification data with document type and confidence
     * @throws PdftractException On command failure
     */
    public function classify($source, array $options = []): array
    {
        $args = array_merge(['classify'], $this->buildArgs($source, $options));
        return $this->exec($args);
    }

    /**
     * Verify a processing receipt
     *
     * @param string $path Path to PDF file
     * @param string $receipt Receipt string to verify
     * @return bool True if receipt is valid, false otherwise
     * @throws PdftractException On command failure
     */
    public function verifyReceipt(string $path, string $receipt): bool
    {
        $args = ['verify-receipt', $path, $receipt];

        $cmd = escapeshellcmd($this->binaryPath);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $this->logger->debug('Executing pdftract verify-receipt command', ['command' => $cmd]);

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            $error = 'Failed to start pdftract process';
            $this->logger->error('Failed to start verify-receipt process', ['command' => $cmd, 'error' => $error]);
            throw new PdftractException($error, -1);
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->logger->error('pdftract verify-receipt command failed', [
                'command' => $cmd,
                'exit_code' => $exitCode,
                'stderr' => $stderr
            ]);
            throw new PdftractException($stderr ?: 'Verify-receipt command failed with no output', $exitCode);
        }

        return trim($stdout) === 'true';
    }
}
