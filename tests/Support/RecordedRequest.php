<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests\Support;

/**
 * One request the loopback server received, as the tests get to see it
 *
 * LoopbackServer parses its request log into these so a test can assert on
 * the exact shape of what the client put on the wire: the method and path,
 * the query, the Authorization and Content-Type headers, the multipart form
 * fields, and the uploaded file's name and content.
 *
 * Header lookups ({@see RecordedRequest::header()}) are case-insensitive;
 * everything else is exactly what the router recorded.
 */
final class RecordedRequest
{
    /**
     * @param array<string, string> $headers Request headers keyed by
     *                                       lowercase header name
     * @param array<string, string> $fields Multipart form fields (what PHP
     *                                      parses into $_POST)
     * @param array<string, list<array{filename: string, mime: string, size: int, sha256: string|null, error: int}>> $uploads
     *                                             Uploaded files, keyed by
     *                                             form field name
     */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly string $queryString,
        private readonly array $query,
        private readonly array $headers,
        private readonly array $fields,
        private readonly array $uploads,
        private readonly int $rawBodyBytes,
        private readonly ?string $rawBodySha256,
    ) {
    }

    /**
     * Build one request from a line of the loopback request log
     *
     * @param array<string, mixed> $record One decoded JSONL record from the
     *                                     router's log
     */
    public static function fromArray(array $record): self
    {
        return new self(
            method: (string)($record['method'] ?? ''),
            path: (string)($record['path'] ?? ''),
            queryString: (string)($record['query_string'] ?? ''),
            query: is_array($record['query'] ?? null) ? $record['query'] : [],
            headers: is_array($record['headers'] ?? null) ? array_map(strval(...), $record['headers']) : [],
            fields: is_array($record['fields'] ?? null) ? array_map(strval(...), $record['fields']) : [],
            uploads: is_array($record['files'] ?? null) ? $record['files'] : [],
            rawBodyBytes: (int)($record['raw_body_bytes'] ?? 0),
            rawBodySha256: is_string($record['raw_body_sha256'] ?? null) ? $record['raw_body_sha256'] : null,
        );
    }

    /** The request method, uppercased */
    public function method(): string
    {
        return $this->method;
    }

    /** The request path, without the query string */
    public function path(): string
    {
        return $this->path;
    }

    /** The raw query string, without the leading '?' (empty when absent) */
    public function queryString(): string
    {
        return $this->queryString;
    }

    /**
     * The parsed query string
     *
     * @return array<string, mixed> What PHP parses $_GET into
     */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * One request header, looked up case-insensitively
     *
     * @param string $name Header name in any case, e.g. 'Authorization'
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Every request header, keyed by lowercase header name
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /** The Authorization header, or null when the request sent none */
    public function authorization(): ?string
    {
        return $this->header('authorization');
    }

    /** The Content-Type header, or null when the request sent none */
    public function contentType(): ?string
    {
        return $this->header('content-type');
    }

    /**
     * The multipart form fields
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /** One multipart form field, or null when the request omitted it */
    public function field(string $name): ?string
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * The filename the client gave the document uploaded in $field
     *
     * @param string $field Multipart field the document is uploaded in
     */
    public function uploadedFilename(string $field = 'file'): ?string
    {
        return $this->uploads()[$field][0]['filename'] ?? null;
    }

    /**
     * The sha256 of the bytes uploaded in $field
     *
     * Null when the upload failed before the server saw any content.
     *
     * @param string $field Multipart field the document is uploaded in
     */
    public function uploadedContentSha256(string $field = 'file'): ?string
    {
        return $this->uploads()[$field][0]['sha256'] ?? null;
    }

    /**
     * Every uploaded file, keyed by multipart field name
     *
     * @return array<string, list<array{filename: string, mime: string, size: int, sha256: string|null, error: int}>>
     */
    public function uploads(): array
    {
        return $this->uploads;
    }

    /** The byte length of the raw request body, whatever its encoding */
    public function rawBodyBytes(): int
    {
        return $this->rawBodyBytes;
    }

    /** The sha256 of the raw request body, or null for an empty body */
    public function rawBodySha256(): ?string
    {
        return $this->rawBodySha256;
    }

    /** A short human-readable form used in assertion failure messages */
    public function describe(): string
    {
        return sprintf('%s %s%s', $this->method, $this->path, $this->queryString !== '' ? '?' . $this->queryString : '');
    }
}
