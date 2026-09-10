<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main client for the pdftract PHP SDK
 *
 * Talks to a `pdftract --serve` HTTP endpoint over curl (ADR-1 in
 * docs/plan/plan.md). Every method POSTs the document as a
 * multipart/form-data upload — the server never reads files from its own
 * filesystem — and maps its responses to PHP values or exceptions:
 *
 * - {@see Client::extract()}      → POST /extract        (JSON)
 * - {@see Client::extractText()}  → POST /extract/text   (plain text)
 * - {@see Client::extractStream()}→ POST /extract/stream (NDJSON, streamed)
 *
 * Every request is bounded by a timeout so a hung server or a stalled
 * stream cannot block the calling PHP process indefinitely:
 *
 * - Buffered calls are bounded by a total wall-clock deadline
 *   (CURLOPT_TIMEOUT).
 * - Streaming calls are bounded by an *idle* timeout: the deadline resets
 *   every time the server produces output, so a long but healthy stream is
 *   not cut off while a silent stall still is.
 *
 * The timeout defaults to self::DEFAULT_TIMEOUT_SECONDS, is configurable
 * per client via the constructor, and can be overridden per call with a
 * 'timeout' option (seconds; 0 disables the bound):
 *
 *     $client = new Client('http://pdftract.internal:8080', null, 600.0);
 *     $client->extract(Source::file('/tmp/big.pdf'), ['timeout' => 900]);
 *
 * The pdftract serve API exposes no HTTP route for the CLI transport's
 * search, getMetadata, hash, classify, verifyReceipt, or extractMarkdown
 * methods, so this client deliberately does not offer them (tracked as
 * bead bf-4bd).
 */
class Client
{
    /** Default wall-clock bound for a request, in seconds. */
    public const DEFAULT_TIMEOUT_SECONDS = 300.0;

    /** Multipart field name the serve API reads the uploaded PDF from. */
    private const FILE_FIELD = 'file';

    /**
     * Multipart field names the serve API treats as the document upload
     *
     * receive_pdf() (pdftract-cli serve.rs) reads *either* name as the
     * upload: the bytes of a part called 'file' or 'pdf' must start with
     * %PDF- or the request is rejected outright with a 400 ("Uploaded file
     * is not a PDF") before extraction is attempted — the server's
     * unknown-field warning only covers names outside this pair. An option
     * key that normalises to one of them can therefore neither ride the
     * request as an ordinary field ('pdf' would be read as upload bytes and
     * fail the magic-byte check) nor be quietly overwritten here ('file'
     * would be silently replaced by the document). Both names are reserved,
     * and an option that claims one is a configuration error, not a field.
     */
    private const RESERVED_UPLOAD_FIELDS = [self::FILE_FIELD, 'pdf'];

    private string $baseUrl;
    private ?string $apiKey;
    private LoggerInterface $logger;
    private float $timeoutSeconds;

    /**
     * Constructor
     *
     * @param string $baseUrl Base URL of the pdftract serve endpoint,
     *                        e.g. 'http://pdftract.internal:8080'
     * @param string|null $apiKey Optional API key. The serve API itself has no
     *                            built-in authentication; deployments that put
     *                            an authenticating reverse proxy in front of
     *                            it receive this as an Authorization: Bearer
     *                            header.
     * @param LoggerInterface|null $logger Optional PSR-3 logger (default: null)
     * @param float $timeoutSeconds Default request timeout in seconds. 0
     *                              disables the bound (default: 300.0)
     * @throws ConfigurationException If the base URL is empty or the timeout
     *                                is negative or not finite
     */
    public function __construct(
        string $baseUrl,
        ?string $apiKey = null,
        ?LoggerInterface $logger = null,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS
    ) {
        if (trim($baseUrl) === '') {
            throw new ConfigurationException('baseUrl must not be empty');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->logger = $logger ?? new NullLogger();
        $this->timeoutSeconds = $this->validateTimeout($timeoutSeconds, 'timeoutSeconds');
    }

    /**
     * Extract structured data from a PDF
     *
     * @param Source|string $pdf Document to extract: a {@see Source}, or raw
     *                           PDF bytes
     * @param array $options Extraction options keyed by camelCase option name
     *                       (e.g. ['ocrLanguage' => 'eng', 'pages' => '1-5']),
     *                       forwarded to the server as multipart form fields.
     *                       The client-side 'timeout' option is popped and
     *                       never forwarded. A key that normalises to the
     *                       reserved upload field names 'file' or 'pdf' is
     *                       rejected outright.
     * @return array Decoded JSON response with schema_version, metadata, pages
     * @throws ConfigurationException If an option is unusable: a key
     *                                normalises to a reserved upload field
     *                                name, or the timeout is invalid
     * @throws PdftractException On server error or undecodable response
     * @throws TimeoutException If the request exceeds its timeout
     * @throws ConnectionException If the server cannot be reached
     */
    public function extract(Source|string $pdf, array $options = []): array
    {
        $timeout = $this->takeTimeout($options);
        $response = $this->request('/extract', $pdf, $options, $timeout);

        $result = json_decode($response['body'], true);

        if (!is_array($result)) {
            return $this->undecodableResponse($response);
        }

        return $result;
    }

    /**
     * Extract plain text from a PDF
     *
     * @param Source|string $pdf Document to extract: a {@see Source}, or raw
     *                           PDF bytes
     * @param array $options Extraction options (see {@see Client::extract()})
     * @return string The response body verbatim: one extracted text span per
     *                line
     * @throws ConfigurationException If an option is unusable (see
     *                                {@see Client::extract()})
     * @throws PdftractException On server error
     * @throws TimeoutException If the request exceeds its timeout
     * @throws ConnectionException If the server cannot be reached
     */
    public function extractText(Source|string $pdf, array $options = []): string
    {
        $timeout = $this->takeTimeout($options);
        $response = $this->request('/extract/text', $pdf, $options, $timeout);

        return $response['body'];
    }

    /**
     * Extract structured data from a PDF as a stream
     *
     * Yields one decoded JSON object per NDJSON line as the server produces
     * it. Blank and undecodable lines are skipped. The 'timeout' option
     * bounds *silence* from the server rather than total run time — see the
     * class docblock. Abandoning the generator early closes the connection.
     *
     * If the server reports a mid-extraction failure it sends a final
     * {"error": ...} line; the client raises that as a PdftractException
     * rather than yielding it as a record.
     *
     * @param Source|string $pdf Document to extract: a {@see Source}, or raw
     *                           PDF bytes
     * @param array $options Extraction options (see {@see Client::extract()})
     * @return \Generator Yields decoded JSON records one at a time
     * @throws ConfigurationException If an option is unusable (see
     *                                {@see Client::extract()}); raised when
     *                                the generator is first iterated
     * @throws PdftractException On server error
     * @throws TimeoutException If the stream stalls longer than the timeout
     * @throws ConnectionException If the server cannot be reached
     */
    public function extractStream(Source|string $pdf, array $options = []): \Generator
    {
        $timeout = $this->takeTimeout($options);
        yield from $this->stream('/extract/stream', $pdf, $options, $timeout);
    }

    /**
     * Get the configured default request timeout
     *
     * @return float Timeout in seconds (0 means unbounded)
     */
    public function getTimeoutSeconds(): float
    {
        return $this->timeoutSeconds;
    }

    /**
     * Get the base URL requests are sent to
     *
     * @return string Base URL without a trailing slash
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
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
            );
        }

        return $timeout;
    }

    /**
     * Pop the client-side 'timeout' option out of an options array
     *
     * The value bounds the request and is never forwarded to the server.
     *
     * @param array $options Options array, modified in place
     * @return float Timeout in seconds
     * @throws ConfigurationException If the option is not a non-negative number
     */
    private function takeTimeout(array &$options): float
    {
        if (!array_key_exists('timeout', $options)) {
            return $this->timeoutSeconds;
        }

        $value = $options['timeout'];
        unset($options['timeout']);

        if (!is_int($value) && !is_float($value)) {
            throw new ConfigurationException(
                'timeout option must be a number of seconds, got: ' . get_debug_type($value),
            );
        }

        return $this->validateTimeout((float)$value, 'timeout option');
    }

    /**
     * Resolve the request's document to raw PDF bytes
     *
     * @param Source|string $pdf Source object or raw PDF bytes
     * @return string PDF bytes to upload
     * @throws PdftractException If a file-backed source cannot be read
     */
    private function resolvePdf(Source|string $pdf): string
    {
        return $pdf instanceof Source ? $pdf->toBytes() : $pdf;
    }

    /**
     * Convert camelCase option names to the serve API's snake_case fields
     *
     * Null and false values are omitted: the server's field defaults are
     * false/absent, and the no_cache field means true by its mere presence,
     * so an explicit false must not be sent.
     *
     * A key that normalises to one of the upload field names
     * ({@see self::RESERVED_UPLOAD_FIELDS}) is rejected rather than dropped
     * or overwritten: the server would read the stray field as the document
     * itself, and this client would otherwise overwrite the caller's value
     * in silence.
     *
     * @param array $options Options with camelCase keys
     * @return array<string, string> Multipart form fields with snake_case keys
     * @throws ConfigurationException If an option key normalises to a
     *                                reserved upload field name
     */
    private function toFormFields(array $options): array
    {
        $fields = [];

        foreach ($options as $key => $value) {
            $field = strtolower(preg_replace('/([A-Z])/', '_$1', lcfirst((string)$key)));

            if (in_array($field, self::RESERVED_UPLOAD_FIELDS, true)) {
                throw new ConfigurationException(
                    sprintf(
                        "option '%s' normalises to the reserved form field '%s', which carries the uploaded document",
                        $key,
                        $field,
                    ),
                );
            }

            if ($value === null || $value === false) {
                continue;
            }

            $fields[$field] = is_bool($value) ? 'true' : (string)$value;
        }

        return $fields;
    }

    /**
     * Headers shared by every request
     *
     * @param string $accept Accept header for the route
     * @return array<int, string> Header lines
     */
    private function headers(string $accept): array
    {
        $headers = ['Accept: ' . $accept];

        // An Expect: 100-continue round trip adds latency to every request
        // and buys nothing for a server that always accepts uploads.
        $headers[] = 'Expect:';

        if ($this->apiKey !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        return $headers;
    }

    /**
     * Shared curl handle options for a multipart POST
     *
     * @param string $path Route path to append to the base URL
     * @param string $pdf PDF bytes to upload
     * @param array $options Extraction options (camelCase keys)
     * @param string $accept Accept header for the route
     * @return array{0: \CurlHandle, 1: string} The handle and the request URL
     */
    private function curlHandle(string $path, string $pdf, array $options, string $accept): array
    {
        $url = $this->baseUrl . $path;

        $handle = curl_init($url);

        if ($handle === false) {
            throw new ConnectionException("Failed to initialise request to {$url}", 'curl_init failed');
        }

        $fields = $this->toFormFields($options);
        $fields[self::FILE_FIELD] = new \CURLStringFile($pdf, 'document.pdf', 'application/pdf');

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => $this->headers($accept),
            CURLOPT_RETURNTRANSFER => true,
        ]);

        return [$handle, $url];
    }

    /**
     * Run a buffered request and return the response
     *
     * Bounded by a total wall-clock timeout. Any non-2xx status, transport
     * failure, or timeout is raised as the matching exception.
     *
     * @param string $path Route path to append to the base URL
     * @param Source|string $pdf Document to upload
     * @param array $options Extraction options (camelCase keys)
     * @param float $timeout Total wall-clock bound in seconds (0 for unbounded)
     * @return array{status: int, body: string, url: string} Response parts
     * @throws PdftractException On non-2xx responses
     * @throws TimeoutException If the request exceeds its timeout
     * @throws ConnectionException If the server cannot be reached
     */
    private function request(string $path, Source|string $pdf, array $options, float $timeout): array
    {
        [$handle, $url] = $this->curlHandle($path, $this->resolvePdf($pdf), $options, 'application/json');
        $this->applyTimeout($handle, $timeout);

        $this->logger->debug('Executing pdftract request', [
            'method' => 'POST',
            'url' => $url,
            'timeout' => $timeout,
        ]);

        $body = curl_exec($handle);

        if ($body === false) {
            throw $this->transportError($handle, $url, $timeout);
        }

        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $response = ['status' => $status, 'body' => (string)$body, 'url' => $url];

        if ($status < 200 || $status >= 300) {
            throw $this->serverError($response);
        }

        return $response;
    }

    /**
     * Apply a timeout to a curl handle
     *
     * @param \CurlHandle $handle Handle to configure
     * @param float $timeout Seconds (0 disables the bound)
     * @throws ConfigurationException If the timeout is negative or not finite
     */
    private function applyTimeout(\CurlHandle $handle, float $timeout): void
    {
        $this->validateTimeout($timeout, 'timeout');

        if ($timeout <= 0) {
            return;
        }

        // Millisecond options so sub-second bounds are honoured exactly.
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, (int)ceil($timeout * 1000));
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, (int)ceil($timeout * 1000));
    }

    /**
     * Raise an exception for a curl-level (no HTTP response) failure
     *
     * @param \CurlHandle $handle Failed handle (closed by this method)
     * @param string $url Request URL, for messages and logs
     * @param float $timeout Timeout the request was bounded by
     * @return TimeoutException|ConnectionException
     */
    private function transportError(\CurlHandle $handle, string $url, float $timeout): TimeoutException|ConnectionException
    {
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            $this->logger->error('pdftract request timed out', ['url' => $url, 'timeout' => $timeout]);

            return new TimeoutException(
                sprintf('pdftract request timed out after %s seconds: %s', $timeout, $url),
                $timeout,
            );
        }

        $this->logger->error('pdftract request failed', ['url' => $url, 'error' => $error]);

        return new ConnectionException("Failed to reach the pdftract server at {$url}: {$error}", $error);
    }

    /**
     * Raise an exception for a non-2xx response
     *
     * The serve API reports every failure as a JSON body of
     * {error, message, hint?}. When the body is not that shape (a proxy
     * interposing its own error page, for instance), the raw body is
     * excerpted into the message instead.
     *
     * @param array{status: int, body: string, url: string} $response Response parts
     * @return PdftractException
     */
    private function serverError(array $response): PdftractException
    {
        $decoded = json_decode($response['body'], true);
        $errorCode = is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : null;
        $message = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : null;
        $hint = is_array($decoded) && is_string($decoded['hint'] ?? null) ? $decoded['hint'] : null;

        if ($message === null) {
            $excerpt = substr($response['body'], 0, 200);
            $message = sprintf('pdftract request failed with HTTP %d: %s', $response['status'], $excerpt);
        }

        if ($hint !== null) {
            $message .= " (hint: {$hint})";
        }

        $this->logger->error('pdftract request failed', [
            'url' => $response['url'],
            'status' => $response['status'],
            'error' => $errorCode,
        ]);

        return new PdftractException($message, $response['status'], $errorCode, $hint);
    }

    /**
     * Raise an exception for a 2xx response whose body is not valid JSON
     *
     * @param array{status: int, body: string, url: string} $response Response parts
     * @return PdftractException Never returned; thrown for typing convenience
     */
    private function undecodableResponse(array $response): PdftractException
    {
        $jsonError = json_last_error_msg();

        $this->logger->error('Failed to decode JSON response', [
            'url' => $response['url'],
            'json_error' => $jsonError,
        ]);

        throw new PdftractException(
            'Failed to decode JSON response: ' . $jsonError,
            $response['status'],
        );
    }

    /**
     * Run a streaming NDJSON request, yielding each record as it arrives
     *
     * The timeout is an *idle* bound enforced with curl_multi: the deadline
     * resets whenever the server produces output, so a long-running but
     * productive stream is not cut off while a silent stall still is.
     * Abandoning the generator early closes the connection.
     *
     * @param string $path Route path to append to the base URL
     * @param Source|string $pdf Document to upload
     * @param array $options Extraction options (camelCase keys)
     * @param float $idleTimeout Seconds of silence tolerated before aborting
     *                           (0 for unbounded)
     * @return \Generator Yields decoded JSON records
     * @throws PdftractException On non-2xx responses or error records
     * @throws TimeoutException If the stream stalls longer than the timeout
     * @throws ConnectionException If the server cannot be reached
     */
    private function stream(string $path, Source|string $pdf, array $options, float $idleTimeout): \Generator
    {
        [$handle, $url] = $this->curlHandle($path, $this->resolvePdf($pdf), $options, 'application/x-ndjson');

        $this->logger->debug('Executing pdftract stream request', [
            'method' => 'POST',
            'url' => $url,
            'idle_timeout' => $idleTimeout,
        ]);

        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $handle);

        // The write callback fires on curl's thread of control while
        // curl_multi_exec() runs, so chunks land in this queue and the
        // generator drains it between iterations.
        $chunks = new \SplQueue();

        curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ($handle, string $chunk) use ($chunks): int {
            $chunks->enqueue($chunk);

            return strlen($chunk);
        });

        $finished = false;

        try {
            $buffer = '';
            $idleDeadline = $idleTimeout > 0 ? microtime(true) + $idleTimeout : null;
            $stillRunning = true;
            $transferResult = CURLE_OK;

            while (true) {
                curl_multi_exec($multi, $stillRunning);

                $sawData = false;
                while (!$chunks->isEmpty()) {
                    $buffer .= $chunks->dequeue();
                    $sawData = true;
                }

                if ($sawData && $idleDeadline !== null) {
                    $idleDeadline = microtime(true) + $idleTimeout;
                }

                $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

                if ($status !== 0 && ($status < 200 || $status >= 300)) {
                    // The server rejected the upload. NDJSON parsing stops
                    // here — the body is the serve API's {error, message}
                    // JSON, not records.
                    $errorBody = $this->collectErrorBody($multi, $handle, $chunks, $buffer, $idleDeadline);
                    throw $this->serverError(['status' => $status, 'body' => $errorBody, 'url' => $url]);
                }

                if ($status !== 0) {
                    // Yield every complete line before waiting for more data.
                    while (($newline = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $newline);
                        $buffer = substr($buffer, $newline + 1);

                        $record = $this->decodeRecord($line, $url);
                        if ($record !== null) {
                            yield $record;
                        }
                    }
                }

                if (!$stillRunning) {
                    // The transfer is complete; its result is queued for read.
                    $info = curl_multi_info_read($multi);
                    $transferResult = $info['result'] ?? CURLE_OK;
                    break;
                }

                // Wait for activity, bounded by how long the stream may stay
                // silent. A short cap keeps abandoned generators from parking
                // in a long select when the deadline is unbounded.
                $waitSeconds = 1.0;
                if ($idleDeadline !== null) {
                    $remaining = $idleDeadline - microtime(true);
                    if ($remaining <= 0) {
                        throw $this->streamTimeout($url, $idleTimeout);
                    }
                    $waitSeconds = min($waitSeconds, $remaining);
                }

                if (curl_multi_select($multi, $waitSeconds) === -1) {
                    // Interrupted (EINTR) or no descriptors yet — brief pause,
                    // then re-exec rather than spinning hot.
                    usleep(1000);
                }
            }

            if ($transferResult !== CURLE_OK) {
                throw $this->streamTransportError($handle, $url, $idleTimeout, $transferResult);
            }

            // Trailing record with no terminating newline.
            $record = $this->decodeRecord($buffer, $url);
            if ($record !== null) {
                yield $record;
            }

            $finished = true;
        } finally {
            // Consumer abandoned the generator (or an error escaped): don't
            // leak the connection or the handles.
            if (!$finished) {
                $this->logger->debug('Abandoning pdftract stream', ['url' => $url]);
            }

            curl_multi_remove_handle($multi, $handle);
            curl_multi_close($multi);
            curl_close($handle);
        }
    }

    /**
     * Collect the body of a rejected streaming request
     *
     * The body of a non-2xx response is the serve API's {error, message}
     * JSON, so it is read to completion and handed to the error builder
     * rather than parsed as records.
     *
     * @param \CurlMultiHandle $multi Multi handle
     * @param \CurlHandle $handle Transfer handle
     * @param \SplQueue $chunks Queue the write callback is filling
     * @param string $buffered Body bytes already delivered
     * @param float|null $idleDeadline Deadline (microtime) bounding the wait
     * @return string The full error body
     */
    private function collectErrorBody(
        \CurlMultiHandle $multi,
        \CurlHandle $handle,
        \SplQueue $chunks,
        string $buffered,
        ?float $idleDeadline
    ): string {
        $body = $buffered;
        $stillRunning = true;

        while ($stillRunning) {
            curl_multi_exec($multi, $stillRunning);

            while (!$chunks->isEmpty()) {
                $body .= $chunks->dequeue();
            }

            if (!$stillRunning) {
                break;
            }

            $waitSeconds = 1.0;
            if ($idleDeadline !== null) {
                $remaining = $idleDeadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }
                $waitSeconds = min($waitSeconds, $remaining);
            }

            if (curl_multi_select($multi, $waitSeconds) === -1) {
                usleep(1000);
            }
        }

        return $body;
    }

    /**
     * Raise an exception for a curl-level failure on a stream
     *
     * @param \CurlHandle $handle Handle (closed by the caller's finally)
     * @param string $url Request URL, for messages and logs
     * @param float $idleTimeout Idle timeout the stream was bounded by
     * @param int $result Curl result code reported for the transfer
     * @return TimeoutException|ConnectionException
     */
    private function streamTransportError(\CurlHandle $handle, string $url, float $idleTimeout, int $result): TimeoutException|ConnectionException
    {
        $error = trim(curl_error($handle)) ?: curl_strerror($result);

        if ($result === CURLE_OPERATION_TIMEDOUT) {
            $this->logger->error('pdftract stream request timed out', ['url' => $url, 'timeout' => $idleTimeout]);

            return new TimeoutException(
                sprintf('pdftract request timed out after %s seconds: %s', $idleTimeout, $url),
                $idleTimeout,
            );
        }

        $this->logger->error('pdftract stream request failed', ['url' => $url, 'error' => $error]);

        return new ConnectionException("Failed to reach the pdftract server at {$url}: {$error}", $error);
    }

    /**
     * Raise a timeout error for a stream that stalled past its idle bound
     *
     * @param string $url Request URL, for messages and logs
     * @param float $idleTimeout Idle timeout that expired
     * @return TimeoutException Never returned; thrown for typing convenience
     */
    private function streamTimeout(string $url, float $idleTimeout): TimeoutException
    {
        $this->logger->error('pdftract stream request timed out', ['url' => $url, 'timeout' => $idleTimeout]);

        throw new TimeoutException(
            sprintf('pdftract stream request timed out after %s seconds of silence: %s', $idleTimeout, $url),
            $idleTimeout,
        );
    }

    /**
     * Decode one NDJSON line, ignoring blank/undecodable lines
     *
     * A line the server sent to report a mid-extraction failure
     * ({"error": ...}) becomes an exception rather than a record.
     *
     * @param string $line Raw line
     * @param string $url Request URL, for error messages
     * @return array|null Decoded record, or null if the line held no record
     * @throws PdftractException If the line is an error record
     */
    private function decodeRecord(string $line, string $url): ?array
    {
        if (trim($line) === '') {
            return null;
        }

        $data = json_decode($line, true);

        if (!is_array($data)) {
            return null;
        }

        $error = $data['error'] ?? null;

        if (is_string($error)) {
            $this->logger->error('pdftract stream reported an error', ['url' => $url, 'error' => $error]);

            throw new PdftractException($error);
        }

        return $data;
    }
}
