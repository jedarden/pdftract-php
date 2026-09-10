<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests\Support;

/**
 * One scripted HTTP response the loopback server answers a request with
 *
 * A ScriptedResponse is bound to a route (a method and a path) and describes
 * what the loopback server sends back when that route is requested: a status,
 * headers, and one of three bodies —
 *
 * - a single body ({@see ScriptedResponse::json()}, {@see ScriptedResponse::text()}),
 * - a sequence of chunks written with controllable inter-chunk delays
 *   ({@see ScriptedResponse::ndjson()}, {@see ScriptedResponse::chunked()}),
 * - nothing at all for a bounded period ({@see ScriptedResponse::stalled()}),
 *   so a client timeout can be exercised offline.
 *
 * When several ScriptedResponses share a route, the server answers with them
 * in the order they were enqueued: the first request to the route gets the
 * first script, the second gets the second, and so on. A request with no
 * script left for its route gets a loud 500 carrying a `loopback_no_script`
 * error code rather than a plausible-looking stub.
 */
final class ScriptedResponse
{
    /**
     * @param array<string, string> $headers Extra response headers, keyed by
     *                                       header name as it should appear
     *                                       on the wire
     * @param list<array{body: string, delaySeconds: float}>|null $chunks Body
     *                                                                    chunks to write in sequence (streaming mode); null when the
     *                                                                    response has a single body
     */
    private function __construct(
        private string $path,
        private string $method,
        private int $status,
        private array $headers,
        private ?string $body,
        private ?array $chunks,
        private float $delaySeconds,
        private bool $stall,
        private float $stallSeconds,
        private string $name,
    ) {
    }

    /** A response whose body is a JSON payload */
    public static function json(string $path, array $payload, string $method = 'POST'): self
    {
        return self::text(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $path,
            $method,
        )->withHeader('Content-Type', 'application/json');
    }

    /** A response whose body is verbatim text */
    public static function text(string $body, string $path, string $method = 'POST'): self
    {
        return new self(
            path: $path,
            method: $method,
            status: 200,
            headers: ['Content-Type' => 'text/plain; charset=UTF-8'],
            body: $body,
            chunks: null,
            delaySeconds: 0.0,
            stall: false,
            stallSeconds: 0.0,
            name: '',
        );
    }

    /**
     * A streaming NDJSON response, one record per line
     *
     * Every line is terminated with a newline, so the client yields one
     * record per line as the chunks arrive. Lines may be blank (the client
     * skips them) and need not be valid JSON (the client skips those too).
     *
     * The delay is applied *between* lines, not after the last one — an
     * inter-chunk delay held open past the final record is wall-clock every
     * streaming test pays for and no test wants.
     *
     * @param list<string> $lines NDJSON lines, without their newlines
     */
    public static function ndjson(string $path, array $lines, float $delaySeconds = 0.0, string $method = 'POST'): self
    {
        $chunks = [];
        $lastIndex = count($lines) - 1;

        foreach ($lines as $index => $line) {
            $chunks[] = [
                'body' => $line . "\n",
                'delaySeconds' => $index === $lastIndex ? 0.0 : $delaySeconds,
            ];
        }

        return self::chunked($path, $chunks, $method)->withHeader('Content-Type', 'application/x-ndjson');
    }

    /**
     * A streaming response written as explicit chunks
     *
     * Each chunk is written, flushed, and followed by its delay, so a client
     * reading incrementally observes the gaps. Use this instead of
     * {@see ScriptedResponse::ndjson()} when a test needs to split a record
     * across chunks, send a final record with no trailing newline, or vary
     * the delay per chunk.
     *
     * @param list<string|array{body: string, delaySeconds?: float}> $chunks
     */
    public static function chunked(string $path, array $chunks, string $method = 'POST'): self
    {
        $spec = [];

        foreach ($chunks as $chunk) {
            $spec[] = is_string($chunk)
                ? ['body' => $chunk, 'delaySeconds' => 0.0]
                : ['body' => (string)($chunk['body'] ?? ''), 'delaySeconds' => (float)($chunk['delaySeconds'] ?? 0.0)];
        }

        return new self(
            path: $path,
            method: $method,
            status: 200,
            headers: ['Content-Type' => 'application/octet-stream'],
            body: null,
            chunks: $spec,
            delaySeconds: 0.0,
            stall: false,
            stallSeconds: 0.0,
            name: '',
        );
    }

    /**
     * A response that never arrives
     *
     * The server records the request, then stays silent for $stallSeconds
     * before answering 503 with a `loopback_stalled` body — a marker no real
     * route produces, so a client that *does* survive the stall fails the
     * test instead of reading a plausible response.
     *
     * A stalled request holds the server for its full duration, so give the
     * client under test a timeout well below $stallSeconds.
     */
    public static function stalled(string $path, float $stallSeconds = 5.0, string $method = 'POST'): self
    {
        return new self(
            path: $path,
            method: $method,
            status: 503,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode([
                'error' => 'loopback_stalled',
                'message' => sprintf('loopback server stalled %s seconds: no response was scripted', $stallSeconds),
            ], JSON_THROW_ON_ERROR),
            chunks: null,
            delaySeconds: 0.0,
            stall: true,
            stallSeconds: $stallSeconds,
            name: '',
        );
    }

    /**
     * A serve-API error body
     *
     * Shapes the {error, message, hint} JSON the pdftract serve API reports
     * failures with, so the client's error mapping can be exercised offline.
     */
    public static function error(
        string $path,
        int $status,
        string $errorCode,
        string $message,
        ?string $hint = null,
        string $method = 'POST',
    ): self {
        $payload = ['error' => $errorCode, 'message' => $message];

        if ($hint !== null) {
            $payload['hint'] = $hint;
        }

        return self::text(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $path,
            $method,
        )->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    /** The same response with a different HTTP status */
    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    /**
     * The same response with one extra (or replaced) header
     *
     * @param string $name Header name as it should appear on the wire
     */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /** The same response, after waiting $delaySeconds before responding */
    public function withDelay(float $delaySeconds): self
    {
        $clone = clone $this;
        $clone->delaySeconds = $delaySeconds;

        return $clone;
    }

    /**
     * The same response under a label used in failure diagnostics
     *
     * The label is reported when a test's request goes unanswered or hits a
     * script the test did not mean to reach.
     */
    public function named(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    /** The route path this response answers */
    public function getPath(): string
    {
        return $this->path;
    }

    /** The request method this response answers */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * A short human-readable label for failure diagnostics
     */
    public function describe(): string
    {
        $label = $this->name !== '' ? " '{$this->name}'" : '';

        return sprintf('%s %s -> %d%s', $this->method, $this->path, $this->status, $label);
    }

    /**
     * The response as the router script reads it
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $this->body,
            'chunks' => $this->chunks,
            'delay_seconds' => $this->delaySeconds,
            'stall' => $this->stall,
            'stall_seconds' => $this->stallSeconds,
            'name' => $this->name,
        ];
    }
}
