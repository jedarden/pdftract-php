<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use Jedarden\Pdftract\Client;
use Jedarden\Pdftract\ConfigurationException;
use Jedarden\Pdftract\ConnectionException;
use Jedarden\Pdftract\PdftractException;
use Jedarden\Pdftract\TimeoutException;
use Jedarden\Pdftract\Source;
use Jedarden\Pdftract\Tests\Support\LoopbackServer;
use Jedarden\Pdftract\Tests\Support\RecordedRequest;
use Jedarden\Pdftract\Tests\Support\ScriptedResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Buffered-route coverage for the canonical HTTP client: POST /extract and
 * POST /extract/text, end to end against the loopback fixture harness.
 *
 * Partition rationale (2026-09-10, bead pdfphp-13beedbc): the buffered-route
 * cases of the legacy suite lived in tests/ClientTest.php and characterized
 * the CLI subprocess transport — argv shaping, stdout parsing, exit-code
 * mapping. ADR-1 (docs/plan/plan.md) retired that transport, and per bf-4gq
 * its cases moved, inert, to tests/Retired/. This file re-holds the same
 * coverage against the HTTP transport, where the equivalent behaviour is:
 *
 * | Retired CLI case                          | Re-held here
 * |-------------------------------------------|----------------------------------------------
 * | extract decodes JSON output               | test_extract_maps_the_json_body_*
 * | extract passes source/options as CLI args | test_extract_posts_*, test_extract_forwards_*
 * | extract throws on undecodable output      | test_extract_raises_on_an_undecodable_*
 * | extract throws stderr on non-zero exit    | test_server_errors_* (status replaces exit code)
 * | extractText returns stdout verbatim       | test_extract_text_returns_the_body_*
 * | extractText passes the text flag first    | test_extract_text_posts_the_document_*
 * | extractText placeholder on empty stderr   | test_extract_text_maps_a_server_error_*
 *
 * and, from the retired subprocess-timeout partition
 * (tests/Retired/ClientSubprocessTimeoutTest.php — the timeout is curl's
 * total-transfer deadline, CURLOPT_TIMEOUT[_MS], instead of a wall-clock
 * guard around a child process):
 *
 * | Retired CLI case                          | Re-held here
 * |-------------------------------------------|----------------------------------------------
 * | buffered call times out, does not block   | test_the_per_call_timeout_option_bounds_the_request, test_the_constructor_default_bounds_*
 * | per-call timeout overrides the default    | test_the_per_call_timeout_option_bounds_*, test_a_zero_per_call_timeout_*
 * | timeout option is not forwarded           | test_extract_forwards_options_as_the_only_snake_case_fields
 * | a fast request inside the bound succeeds  | test_a_healthy_request_completes_inside_the_bound
 * | zero timeout means unbounded              | test_a_zero_per_call_timeout_*, test_a_zero_constructor_timeout_*
 * | negative/non-numeric timeouts rejected    | test_an_invalid_*_raises_a_configuration_exception
 *
 * The cases that drove the six methods the serve API cannot reach stay parked
 * in tests/ClientTest.php (bf-4bd), and the streaming route is covered by its
 * own suite — neither belongs here.
 *
 * Three further retired cases have no HTTP equivalent to re-hold, which is
 * not the same as dropped coverage — each guarded a mechanic that died with
 * the subprocess transport:
 *
 * | Retired CLI case                          | Why there is no re-hold
 * |-------------------------------------------|-------------------------------------------
 * | URL source passed as --url flag           | the serve API only accepts multipart uploads (src/Source.php)
 * | stdin source passed as "-"                | ditto — there is no stdin field on the wire
 * | timed-out child is terminated             | there is no child; curl_close() reclaims the handle
 * | large stderr does not deadlock            | there is no stderr pipe to fill; curl drains the body itself
 *
 * Every case runs offline: the loopback server answers from scripted
 * responses on an ephemeral 127.0.0.1 port, so nothing here can reach the
 * network beyond loopback.
 */
#[Group('http-client')]
#[Group('buffered-routes')]
final class ClientBufferedRouteTest extends TestCase
{
    /**
     * Fixture document bytes
     *
     * Deliberately carries a NUL byte and non-ASCII text: the document is
     * uploaded as a multipart part, and the upload must survive both.
     */
    private const PDF_BYTES = "%PDF-1.7\n%\x00buffered-route fixture — naïve bytes\n";

    /**
     * A serve-API extraction result, with the field names and nesting the
     * documented response schema actually uses (models/Document, Page, Span,
     * Block, ExtractionQuality, Diagnostic): schema_version, metadata,
     * pages, extraction_quality, errors. The values are chosen so the
     * mapping must preserve the shapes and PHP types the schema
     * distinguishes — nested lists vs maps, int vs float (page_index 0,
     * ocr_fraction 0.25), booleans, nulls, and non-ASCII text. Every float
     * here deliberately carries a fraction: the harness writes the body with
     * a plain json_encode, which flattens an integral float (612.0) to an
     * int on the wire — the client would then faithfully map an int, and the
     * assertion would fail on the harness, not on the client.
     */
    private const DOCUMENT = [
        'schema_version' => '1.0',
        'metadata' => [
            'title' => 'Buffered-route fixture — 中文',
            'author' => null,
            'page_count' => 2,
            'pdf_version' => '1.7',
            'is_encrypted' => false,
            'contains_javascript' => false,
        ],
        'pages' => [
            [
                'page_index' => 0,
                'page_number' => 1,
                'page_label' => null,
                'width' => 612.5,
                'height' => 791.25,
                'rotation' => 0,
                'type' => 'text',
                'spans' => [
                    [
                        'text' => "first page\nsecond line",
                        'bbox' => [72.5, 700.25, 540.5, 712.5],
                        'font' => 'Times-Roman',
                        'size' => 11.5,
                        'confidence' => 0.987,
                        'flags' => [],
                    ],
                ],
                'blocks' => [
                    [
                        'kind' => 'heading',
                        'text' => 'first page',
                        'bbox' => [72.25, 690.5, 300.25, 720.5],
                    ],
                ],
                'tables' => [],
                'annotations' => [],
            ],
            [
                'page_index' => 1,
                'page_number' => 2,
                'page_label' => 'iv',
                'width' => 612.5,
                'height' => 791.25,
                'rotation' => 90,
                'type' => 'blank',
                'spans' => [],
                'blocks' => [],
                'tables' => [],
                'annotations' => [],
            ],
        ],
        'extraction_quality' => [
            'overall_quality' => 'good',
            'dpi_used' => null,
            'ocr_fraction' => 0.25,
            'min_confidence' => 0.987,
            'avg_confidence' => 0.987,
            'readability' => null,
        ],
        'errors' => [
            [
                'code' => 'EMPTY_PAGE',
                'message' => 'page 2 has no extractable text',
                'severity' => 'warning',
                'page_index' => 1,
                'hint' => null,
            ],
        ],
    ];

    /**
     * Options every buffered call may take, and the multipart fields the
     * serve API must then receive
     *
     * Pinned as a constant pair so the forwarding test asserts the exact
     * field set against a named contract rather than re-writing both sides
     * inline, where they could drift apart. Every name actually forwarded
     * from here is one of the multipart fields the serve API reads
     * (pdftract serve's KNOWN_FIELDS: file, pdf, receipts, no_cache,
     * full_render, max_decompress_gb, ocr_language, ocr_dpi,
     * markdown_anchors, pages); the dropped false/null entries and the
     * client-side 'timeout' option never reach the wire at all. The
     * retired CLI transport's --fast and --skip-text flags have no
     * serve equivalent and must not creep back in as exemplars.
     */
    private const FORWARDABLE_OPTIONS = [
        'ocrLanguage' => 'eng',
        'pages' => '1-3',
        'fullRender' => true,
        'noCache' => false,
        'ocrDpi' => null,
        'timeout' => 30,
    ];

    /**
     * What {@see self::FORWARDABLE_OPTIONS} must arrive as: camelCase names
     * become the serve API's snake_case fields; false and null mean "server
     * default" and are dropped, and the client-side 'timeout' option is
     * consumed, never forwarded
     */
    private const FORWARDED_FIELDS = [
        'ocr_language' => 'eng',
        'pages' => '1-3',
        'full_render' => 'true',
    ];

    private LoopbackServer $server;

    protected function setUp(): void
    {
        $this->requireCurl();
        $this->server = LoopbackServer::start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    // --------------------------------------------------------- request shape

    public function test_extract_posts_the_document_as_multipart_to_the_extract_route(): void
    {
        $this->server->enqueue(
            ScriptedResponse::json('/extract', self::DOCUMENT)->named('extract happy path'),
        );

        $client = new Client($this->server->baseUri(), 'secret-key');

        self::assertSame(self::DOCUMENT, $client->extract(Source::bytes(self::PDF_BYTES)));

        $request = $this->server->lastRequest();
        self::assertNotNull($request, 'the request never reached the loopback server');

        self::assertSame('POST', $request->method(), $request->describe());
        self::assertSame('/extract', $request->path(), $request->describe());
        self::assertSame('', $request->queryString(), $request->describe());
        self::assertSame('Bearer secret-key', $request->authorization(), $request->describe());

        // The declared type and a boundary that is actually present — not
        // merely a "boundary=" parameter that could be empty. The router
        // parses the body with PHP's multipart parser, so a missing or
        // mismatched boundary also empties the uploads below.
        self::assertMatchesRegularExpression(
            '~^multipart/form-data; boundary=\S+~',
            (string)$request->contentType(),
            $request->describe(),
        );

        $this->assertDocumentUpload($request);
        self::assertSame([], $request->fields(), 'a call without options must send no form fields');
    }

    public function test_extract_text_posts_the_document_to_the_text_route(): void
    {
        $this->server->enqueue(
            ScriptedResponse::text("span one\nspan two\n", '/extract/text')->named('text happy path'),
        );

        $client = new Client($this->server->baseUri(), 'secret-key');

        self::assertSame("span one\nspan two\n", $client->extractText(Source::bytes(self::PDF_BYTES)));

        $request = $this->server->lastRequest();
        self::assertNotNull($request, 'the request never reached the loopback server');

        self::assertSame('POST', $request->method(), $request->describe());
        self::assertSame('/extract/text', $request->path(), $request->describe());
        self::assertSame('', $request->queryString(), $request->describe());
        self::assertSame('Bearer secret-key', $request->authorization(), $request->describe());
        self::assertMatchesRegularExpression(
            '~^multipart/form-data; boundary=\S+~',
            (string)$request->contentType(),
            $request->describe(),
        );

        $this->assertDocumentUpload($request);
        self::assertSame([], $request->fields(), 'a call without options must send no form fields');
    }

    public function test_the_api_key_header_is_omitted_when_no_key_is_configured(): void
    {
        $this->server->enqueue(
            ScriptedResponse::json('/extract', self::DOCUMENT),
            ScriptedResponse::text('span', '/extract/text'),
        );

        $client = new Client($this->server->baseUri());
        $client->extract(Source::bytes(self::PDF_BYTES));
        $client->extractText(Source::bytes(self::PDF_BYTES));

        foreach ($this->server->requests() as $request) {
            self::assertNull(
                $request->authorization(),
                "the client must not send an Authorization header without a configured key ({$request->describe()})",
            );
        }
    }

    public function test_the_api_key_header_is_a_bearer_header_when_a_key_is_configured(): void
    {
        // The header is built once for every request (src/Client.php
        // headers()), so a key configured once must reach both buffered
        // routes identically: the Bearer scheme, a single space, then the
        // key exactly as given.
        $key = 'pdftract-test-key-9f2c';

        $this->server->enqueue(
            ScriptedResponse::json('/extract', self::DOCUMENT),
            ScriptedResponse::text('span', '/extract/text'),
        );

        $client = new Client($this->server->baseUri(), $key);
        $client->extract(Source::bytes(self::PDF_BYTES));
        $client->extractText(Source::bytes(self::PDF_BYTES));

        $requests = $this->server->requests();

        self::assertCount(2, $requests, 'both buffered routes must have been exercised');

        foreach ($requests as $request) {
            self::assertSame(
                'Bearer ' . $key,
                $request->authorization(),
                "the configured key must travel as exactly 'Authorization: Bearer <key>' ({$request->describe()})",
            );
        }
    }

    public function test_extract_forwards_options_as_the_only_snake_case_fields(): void
    {
        $this->server->enqueue(ScriptedResponse::json('/extract', self::DOCUMENT));

        $client = new Client($this->server->baseUri());
        $client->extract(Source::bytes(self::PDF_BYTES), self::FORWARDABLE_OPTIONS);

        $request = $this->server->lastRequest();
        self::assertNotNull($request);

        // camelCase options become the serve API's snake_case fields; false
        // and null mean "server default" and are dropped — dropping
        // no_cache=false is load-bearing, because the server reads that
        // field as true by its mere presence, so an explicit false would
        // silently disable the cache; the client-side 'timeout' option is
        // consumed, never forwarded. assertSame pins the whole map, so a
        // forwarded unexpected field, a camelCase name, an explicit false
        // or null, or a value mangled in transit fails too.
        self::assertSame(
            self::FORWARDED_FIELDS,
            $request->fields(),
            $request->describe(),
        );

        // "The only additional fields" means additional to the document: an
        // options-bearing request must still carry the upload, exactly as an
        // optionless one does. The server warns about unknown fields and
        // ignores them, so nothing but this assertion catches a client that
        // dropped the file part once it had fields to send.
        $this->assertDocumentUpload($request);
        self::assertSame('', $request->queryString(), 'options travel as form fields, not as a query string');
    }

    public function test_a_file_source_uploads_the_file_bytes(): void
    {
        $path = sys_get_temp_dir() . '/pdftract-buffered-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, self::PDF_BYTES);

        try {
            $this->server->enqueue(ScriptedResponse::json('/extract', self::DOCUMENT));

            $client = new Client($this->server->baseUri());
            $client->extract(Source::file($path));

            $request = $this->server->lastRequest();
            self::assertNotNull($request);

            // Reading from a file changes nothing about the wire: the upload
            // must carry the same field, filename, media type, and byte count
            // a bytes-backed source does — the source only decides what is
            // read, not how it is sent.
            $this->assertDocumentUpload($request);

            // The path must not reach the server in any position (bead
            // pdfphp-3a1ed8a2, by mutation: content, filename, sibling field
            // and query string each detected): not as the part's content,
            // not as the filename, not as a sibling form field, and not as
            // a query string — the serve API reads documents out of uploads
            // only.
            self::assertSame([], $request->fields(), 'the source path must not travel as a form field');
            self::assertSame('', $request->queryString(), 'the source path must not travel as a query string');
            self::assertSame(
                hash('sha256', self::PDF_BYTES),
                $request->uploadedContentSha256(),
                'the client must upload the file bytes, not the path — as content or as filename',
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_a_file_source_with_options_sends_only_the_forwarded_fields_and_the_bytes(): void
    {
        // The two field-minimality guarantees interact: a file-backed source
        // hands the client a path it could leak as a form field, and
        // non-empty options are the only state in which the multipart body
        // has fields for the upload to ride alongside. Neither may disturb
        // the other — the fields stay exactly the forwarded set, so the path
        // is not one of them, and the upload stays the file's bytes.
        $path = sys_get_temp_dir() . '/pdftract-buffered-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, self::PDF_BYTES);

        try {
            $this->server->enqueue(ScriptedResponse::json('/extract', self::DOCUMENT));

            $client = new Client($this->server->baseUri());
            $client->extract(Source::file($path), self::FORWARDABLE_OPTIONS);

            $request = $this->server->lastRequest();
            self::assertNotNull($request);

            self::assertSame(self::FORWARDED_FIELDS, $request->fields(), $request->describe());
            $this->assertDocumentUpload($request);
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------------------------ response mapping

    public function test_extract_maps_the_json_body_to_the_documented_php_value(): void
    {
        $this->server->enqueue(ScriptedResponse::json('/extract', self::DOCUMENT));

        $client = new Client($this->server->baseUri());

        // assertSame, not assertEquals: nested shape, list/assoc distinction,
        // int vs float, booleans and nulls must all survive the round trip.
        self::assertSame(self::DOCUMENT, $client->extract(Source::bytes(self::PDF_BYTES)));
    }

    public function test_extract_text_returns_the_body_verbatim(): void
    {
        $body = "  leading space kept\nmulti\nline\nwith a trailing newline\n";

        $this->server->enqueue(ScriptedResponse::text($body, '/extract/text'));

        $client = new Client($this->server->baseUri());

        self::assertSame($body, $client->extractText(Source::bytes(self::PDF_BYTES)));
    }

    public function test_extract_text_returns_a_json_shaped_body_verbatim(): void
    {
        // The text route is text: even a body that would parse as JSON (or
        // as an error record) must come back exactly as the server sent it.
        $body = '{"error": "not a record, just text"}';

        $this->server->enqueue(ScriptedResponse::text($body, '/extract/text'));

        $client = new Client($this->server->baseUri());

        self::assertSame($body, $client->extractText(Source::bytes(self::PDF_BYTES)));
    }

    public function test_extract_text_returns_an_empty_body_as_an_empty_string(): void
    {
        $this->server->enqueue(ScriptedResponse::text('', '/extract/text'));

        $client = new Client($this->server->baseUri());

        self::assertSame('', $client->extractText(Source::bytes(self::PDF_BYTES)));
    }

    // ----------------------------------------------------------- error paths

    #[DataProvider('provideServerErrorResponses')]
    public function test_server_errors_map_to_the_documented_exception(
        int $status,
        string $errorCode,
        string $message,
        ?string $hint,
    ): void {
        $this->server->enqueue(
            ScriptedResponse::error('/extract', $status, $errorCode, $message, $hint),
        );

        $client = new Client($this->server->baseUri());

        $exception = null;

        try {
            $client->extract(Source::bytes(self::PDF_BYTES));
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'a non-2xx response must raise PdftractException');
        self::assertInstanceOf(PdftractException::class, $exception);
        self::assertSame($status, $exception->getStatusCode(), 'the HTTP status must be preserved');
        self::assertSame($status, $exception->getCode(), 'the exception code carries the HTTP status');
        self::assertSame($errorCode, $exception->getErrorCode(), 'the serve API error code must be preserved');
        self::assertStringContainsString($message, $exception->getMessage());

        if ($hint !== null) {
            self::assertSame($hint, $exception->getHint(), 'the server hint must be preserved');
            self::assertStringContainsString($hint, $exception->getMessage(), 'the hint should surface to the caller');
        } else {
            self::assertNull($exception->getHint());
        }
    }

    public static function provideServerErrorResponses(): array
    {
        return [
            '400 validation' => [400, 'INVALID_REQUEST', 'pages option is not a page range', 'use N or N-M'],
            '404 not found' => [404, 'NOT_FOUND', 'no such document', null],
            '429 rate limited' => [429, 'RATE_LIMITED', 'too many extraction requests', 'retry after the window'],
            '500 server error' => [500, 'INTERNAL_ERROR', 'the extractor crashed on page 7', null],
            '503 unavailable' => [503, 'UNAVAILABLE', 'pdftract is starting up', null],
        ];
    }

    public function test_extract_text_maps_a_server_error_to_the_documented_exception(): void
    {
        $this->server->enqueue(
            ScriptedResponse::error('/extract/text', 422, 'ENCRYPTED', 'document requires a password', 'pass password'),
        );

        $client = new Client($this->server->baseUri());

        $exception = null;

        try {
            $client->extractText(Source::bytes(self::PDF_BYTES));
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'a non-2xx response must raise PdftractException on the text route too');
        self::assertSame(422, $exception->getStatusCode());
        self::assertSame('ENCRYPTED', $exception->getErrorCode());
        self::assertStringContainsString('document requires a password', $exception->getMessage());
        self::assertSame('pass password', $exception->getHint());
    }

    #[DataProvider('provideNonJsonErrorBodies')]
    public function test_an_error_body_that_is_not_the_serve_api_shape_is_excerpted(
        int $status,
        string $body,
    ): void {
        $this->server->enqueue(
            ScriptedResponse::text($body, '/extract/text')->withStatus($status)->named('proxy error page'),
        );

        $client = new Client($this->server->baseUri());

        $exception = null;

        try {
            $client->extractText(Source::bytes(self::PDF_BYTES));
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        // A reverse proxy interposing its own error page must not be mistaken
        // for a serve-API error: the client reports the status and excerpts
        // the raw body instead of inventing fields.
        self::assertNotNull($exception, 'a non-2xx response must raise PdftractException even without an error body');
        self::assertSame($status, $exception->getStatusCode());
        self::assertNull($exception->getErrorCode(), 'no serve-API error code can be read from a foreign body');
        self::assertNull($exception->getHint());
        self::assertStringContainsString((string)$status, $exception->getMessage());
        self::assertStringContainsString($body, $exception->getMessage(), 'the raw body should be excerpted');
    }

    public static function provideNonJsonErrorBodies(): array
    {
        return [
            '502 html proxy page' => [502, '<html><body>502 Bad Gateway</body></html>'],
            '500 empty body' => [500, ''],
            '502 truncated json' => [502, '{"error": "gateway'],
        ];
    }

    #[DataProvider('provideUndecodableSuccessBodies')]
    public function test_extract_raises_on_an_undecodable_success_body(string $body): void
    {
        $this->server->enqueue(ScriptedResponse::text($body, '/extract')->named('undecodable body'));

        $client = new Client($this->server->baseUri());

        $exception = null;

        try {
            $client->extract(Source::bytes(self::PDF_BYTES));
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'a 2xx response whose body is not a JSON object must raise');
        self::assertStringContainsString('Failed to decode JSON response', $exception->getMessage());
        self::assertStringContainsString('Syntax error', $exception->getMessage());
        self::assertSame(200, $exception->getStatusCode(), 'the failure is an encoding error, not a server error');
        self::assertNull($exception->getErrorCode());
    }

    public static function provideUndecodableSuccessBodies(): array
    {
        return [
            'html error page' => ['<html><body>moved</body></html>'],
            'truncated json' => ['{"schema_version": "1.0", "pages": [{"n"'],
            'empty body' => [''],
            'whitespace body' => ["\n  \t"],
        ];
    }

    #[DataProvider('provideNonObjectJsonSuccessBodies')]
    public function test_extract_raises_when_a_valid_json_body_is_not_an_object(string $body): void
    {
        // 'null' and '42' are well-formed JSON that decodes without error —
        // the failure is the shape, not the encoding, but it surfaces the
        // same way: no array, no extraction result.
        $this->server->enqueue(ScriptedResponse::text($body, '/extract'));

        $client = new Client($this->server->baseUri());

        $this->expectException(PdftractException::class);
        $this->expectExceptionMessage('Failed to decode JSON response');

        $client->extract(Source::bytes(self::PDF_BYTES));
    }

    public static function provideNonObjectJsonSuccessBodies(): array
    {
        return [
            'json null' => ['null'],
            'json scalar' => ['42'],
            'json string' => ['"a document"'],
        ];
    }

    // ------------------------------------------------------ transport errors

    public function test_a_refused_connection_surfaces_as_connection_exception(): void
    {
        // Take a real bound port away: connecting to it is refused, not
        // silent, so this exercises curl's "no HTTP response at all" path.
        $deadServer = LoopbackServer::start();
        $baseUri = $deadServer->baseUri();
        $deadServer->stop();

        $client = new Client($baseUri);

        $exception = null;

        try {
            $client->extract(Source::bytes(self::PDF_BYTES));
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'extract() must not survive a refused connection');
        self::assertInstanceOf(ConnectionException::class, $exception);
        self::assertStringContainsString($baseUri, $exception->getMessage());
        self::assertNotSame('', $exception->getReason(), 'the underlying curl error should be carried');
        self::assertNull($exception->getStatusCode(), 'a transport failure never reached the server');
        self::assertNull($exception->getErrorCode());

        // The text route takes the same path: no HTTP response, one typed
        // exception, no bare curl warning leaking out.
        $textException = null;

        try {
            $client->extractText(Source::bytes(self::PDF_BYTES));
        } catch (PdftractException $caught) {
            $textException = $caught;
        }

        self::assertInstanceOf(ConnectionException::class, $textException, 'extractText() must map a refused connection too');
    }

    public function test_an_unresolvable_host_surfaces_as_connection_exception(): void
    {
        // .invalid is reserved and never resolves, so this is an offline DNS
        // failure rather than a request that escapes the loopback harness.
        $client = new Client('http://pdftract-buffered-suite.unresolvable.invalid');

        $exception = null;

        try {
            $client->extract(Source::bytes(self::PDF_BYTES));
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'a DNS failure must not surface as a bare curl warning');
        self::assertInstanceOf(ConnectionException::class, $exception);
        self::assertStringContainsString('pdftract-buffered-suite.unresolvable.invalid', $exception->getMessage());
        self::assertNotSame('', $exception->getReason());
        self::assertNull($exception->getStatusCode());
    }

    // ---------------------------------------------------------------- logging

    public function test_a_buffered_request_and_its_failure_are_logged(): void
    {
        $logger = new RecordingLogger();

        $this->server->enqueue(
            ScriptedResponse::error('/extract', 500, 'INTERNAL_ERROR', 'boom'),
        );

        $client = new Client($this->server->baseUri(), 'secret-key', $logger, 12.0);

        try {
            $client->extract(Source::bytes(self::PDF_BYTES));
            self::fail('expected PdftractException');
        } catch (PdftractException) {
            // asserted below
        }

        self::assertSame(
            [
                ['debug', 'Executing pdftract request'],
                ['error', 'pdftract request failed'],
            ],
            $logger->messages(),
        );

        $request = $this->server->lastRequest();
        self::assertNotNull($request);

        $debugContext = $logger->records[0]['context'];
        self::assertSame('POST', $debugContext['method']);
        self::assertSame($this->server->baseUri() . '/extract', $debugContext['url']);
        self::assertSame(12.0, $debugContext['timeout']);

        $errorContext = $logger->records[1]['context'];
        self::assertSame($this->server->baseUri() . '/extract', $errorContext['url']);
        self::assertSame(500, $errorContext['status']);
        self::assertSame('INTERNAL_ERROR', $errorContext['error']);
    }

    // -------------------------------------------------------------- timeouts

    public function test_the_per_call_timeout_option_bounds_the_request(): void
    {
        // A 5 second stall against a 0.25 second bound: the client must give
        // up on its own deadline, not wait the stall out.
        $this->server->enqueue(ScriptedResponse::stalled('/extract', 5.0));

        $client = new Client($this->server->baseUri(), null, null, 30.0);

        $startedAt = microtime(true);
        $exception = null;

        try {
            $client->extract(Source::bytes(self::PDF_BYTES), ['timeout' => 0.25]);
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        $elapsed = microtime(true) - $startedAt;

        self::assertInstanceOf(TimeoutException::class, $exception, 'a request past its per-call bound must raise TimeoutException');
        self::assertInstanceOf(PdftractException::class, $exception);
        self::assertSame(0.25, $exception->getTimeoutSeconds(), 'the exception must report the bound it hit');
        self::assertStringContainsString('0.25', $exception->getMessage());
        self::assertStringContainsString($this->server->baseUri(), $exception->getMessage());
        self::assertNull($exception->getStatusCode(), 'a timeout never produced an HTTP response');
        self::assertNull($exception->getErrorCode());

        // The bound is curl's total-transfer deadline, so the abort lands on
        // it: after the requested bound, and long before the stall ends.
        self::assertGreaterThanOrEqual(0.25, $elapsed);
        self::assertLessThan(3.0, $elapsed, 'the client must abort on its deadline, not block for the stall');

        // The request still reached the server — the harness records requests
        // even when their response is never read.
        self::assertNotNull($this->server->lastRequest(), 'the timed-out request never reached the loopback server');
    }

    public function test_the_per_call_timeout_option_bounds_the_text_route_too(): void
    {
        $this->server->enqueue(ScriptedResponse::stalled('/extract/text', 5.0));

        $client = new Client($this->server->baseUri(), null, null, 30.0);

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('timed out');

        $client->extractText(Source::bytes(self::PDF_BYTES), ['timeout' => 0.25]);
    }

    public function test_the_constructor_default_bounds_a_request_without_a_per_call_override(): void
    {
        $this->server->enqueue(
            ScriptedResponse::stalled('/extract', 5.0),
            ScriptedResponse::stalled('/extract/text', 5.0),
        );

        $client = new Client($this->server->baseUri(), null, null, 0.25);

        $extractException = null;

        try {
            $client->extract(Source::bytes(self::PDF_BYTES));
        } catch (PdftractException $caught) {
            $extractException = $caught;
        }

        self::assertInstanceOf(TimeoutException::class, $extractException, 'the constructor default must bound extract()');
        self::assertSame(0.25, $extractException->getTimeoutSeconds());

        // extractText() shares the constructor default.
        $textException = null;

        try {
            $client->extractText(Source::bytes(self::PDF_BYTES));
        } catch (PdftractException $caught) {
            $textException = $caught;
        }

        self::assertInstanceOf(TimeoutException::class, $textException, 'the constructor default must bound extractText()');
        self::assertSame(0.25, $textException->getTimeoutSeconds());
    }

    public function test_a_healthy_request_completes_inside_the_bound(): void
    {
        // A deadline respected is also a deadline not misfired: responses
        // arriving inside it must come back as results, not as timeouts.
        $this->server->enqueue(
            ScriptedResponse::json('/extract', self::DOCUMENT),
            ScriptedResponse::text('span one', '/extract/text'),
        );

        $client = new Client($this->server->baseUri(), null, null, 0.25);

        self::assertSame(self::DOCUMENT, $client->extract(Source::bytes(self::PDF_BYTES)));
        self::assertSame('span one', $client->extractText(Source::bytes(self::PDF_BYTES)));
    }

    public function test_a_zero_per_call_timeout_disables_the_bound(): void
    {
        // The 0.25 second constructor default would cut a response delayed
        // past it off; the per-call 0 removes the bound entirely, so the
        // delayed response arrives and is mapped normally.
        $this->server->enqueue(
            ScriptedResponse::json('/extract', self::DOCUMENT)->withDelay(1.0),
        );

        $client = new Client($this->server->baseUri(), null, null, 0.25);

        self::assertSame(
            self::DOCUMENT,
            $client->extract(Source::bytes(self::PDF_BYTES), ['timeout' => 0]),
        );
    }

    public function test_a_zero_constructor_timeout_disables_the_default_bound(): void
    {
        $this->server->enqueue(
            ScriptedResponse::text('late span', '/extract/text')->withDelay(1.0),
        );

        $client = new Client($this->server->baseUri(), null, null, 0.0);

        self::assertSame('late span', $client->extractText(Source::bytes(self::PDF_BYTES)));
    }

    // -------------------------------------------------------- configuration

    #[DataProvider('provideInvalidBaseUrls')]
    public function test_an_empty_base_url_raises_a_configuration_exception(string $baseUrl): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('baseUrl');

        new Client($baseUrl);
    }

    public static function provideInvalidBaseUrls(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => [" \t\r\n"],
        ];
    }

    #[DataProvider('provideInvalidConstructorTimeouts')]
    public function test_an_invalid_constructor_timeout_raises_a_configuration_exception(float $timeout): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('timeoutSeconds');

        new Client($this->server->baseUri(), null, null, $timeout);
    }

    public static function provideInvalidConstructorTimeouts(): array
    {
        // The non-finite timeouts (NAN, INF) cannot travel through a data
        // provider, so they get their own case below.
        return [
            'negative' => [-1.0],
            'negative fraction' => [-0.5],
        ];
    }

    public function test_a_non_finite_constructor_timeout_raises_a_configuration_exception(): void
    {
        foreach ([NAN, INF, -INF] as $timeout) {
            try {
                new Client($this->server->baseUri(), null, null, $timeout);
                self::fail("a non-finite timeoutSeconds must be rejected, got: {$timeout}");
            } catch (ConfigurationException $caught) {
                self::assertStringContainsString('timeoutSeconds', $caught->getMessage());
            }
        }
    }

    #[DataProvider('provideInvalidPerCallTimeouts')]
    public function test_an_invalid_per_call_timeout_option_raises_a_configuration_exception(mixed $timeout): void
    {
        $client = new Client($this->server->baseUri());

        $exception = null;

        try {
            $client->extract(Source::bytes(self::PDF_BYTES), ['timeout' => $timeout]);
        } catch (ConfigurationException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'an unusable per-call timeout must be rejected as a configuration error');
        self::assertInstanceOf(PdftractException::class, $exception);
        self::assertStringContainsString('timeout option', $exception->getMessage());
        self::assertNull($this->server->lastRequest(), 'validation must happen before anything reaches the network');
    }

    public static function provideInvalidPerCallTimeouts(): array
    {
        return [
            'negative' => [-2.5],
            'not a number' => ['soon'],
        ];
    }

    public function test_a_nan_per_call_timeout_option_raises_a_configuration_exception(): void
    {
        $client = new Client($this->server->baseUri());

        try {
            $client->extract(Source::bytes(self::PDF_BYTES), ['timeout' => NAN]);
            self::fail('a non-finite per-call timeout must be rejected');
        } catch (ConfigurationException $caught) {
            self::assertStringContainsString('timeout option', $caught->getMessage());
        }

        self::assertNull($this->server->lastRequest(), 'validation must happen before anything reaches the network');
    }

    // --------------------------------------------------------------- helpers

    /**
     * Assert the request uploaded the fixture document the way the serve API
     * reads it: one part, in the documented field, with the client's fixed
     * filename, PDF media type, and the exact bytes it was given
     */
    private function assertDocumentUpload(RecordedRequest $request): void
    {
        self::assertSame(['file'], array_keys($request->uploads()), 'the document must travel in the serve API field');
        self::assertCount(1, $request->uploads()['file'], $request->describe());

        $upload = $request->uploads()['file'][0];

        self::assertSame('document.pdf', $upload['filename'], $request->describe());
        self::assertSame('application/pdf', $upload['mime'], $request->describe());
        self::assertSame(strlen(self::PDF_BYTES), $upload['size'], $request->describe());
        self::assertSame(hash('sha256', self::PDF_BYTES), $upload['sha256'], $request->describe());
        self::assertSame(0, $upload['error'], 'the upload must arrive intact');
        self::assertSame('document.pdf', $request->uploadedFilename(), $request->describe());
    }

    /** The client and the harness both speak HTTP over curl */
    private function requireCurl(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('The curl extension is required for the HTTP client.');
        }
    }
}

/**
 * PSR-3 logger that keeps every record for assertion
 */
class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string)$level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }

    /**
     * @return array<int, array{0: string, 1: string}> level/message pairs, in order
     */
    public function messages(): array
    {
        return array_map(
            static fn (array $record): array => [$record['level'], $record['message']],
            $this->records,
        );
    }
}
