<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use Jedarden\Pdftract\Client;
use Jedarden\Pdftract\ConnectionException;
use Jedarden\Pdftract\PdftractException;
use Jedarden\Pdftract\Source;
use Jedarden\Pdftract\Tests\Support\LoopbackServer;
use Jedarden\Pdftract\Tests\Support\RecordedRequest;
use Jedarden\Pdftract\Tests\Support\ScriptedResponse;
use Jedarden\Pdftract\TimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Streaming-route coverage for the canonical HTTP client: POST
 * /extract/stream, end to end against the loopback fixture harness.
 *
 * Partition rationale (2026-09-11, bead pdfphp-98dc97ba): the streaming
 * cases of the legacy suite lived in tests/ClientTest.php and
 * tests/ClientTimeoutTest.php and characterized the CLI subprocess transport
 * — NDJSON lines read out of a child's stdout, an idle bound enforced around
 * a live process. ADR-1 (docs/plan/plan.md) retired that transport, and per
 * bf-4gq those cases moved, inert, to tests/Retired/. This file re-holds the
 * same coverage against the HTTP transport, where the equivalent behaviour
 * is (src/Client.php stream()):
 *
 * | Retired CLI case                                  | Re-held here
 * |---------------------------------------------------|-----------------------------------------------------------
 * | extractStream yields one decoded object per line  | test_a_healthy_stream_yields_each_record_*
 * | stream skips blank and undecodable lines          | test_blank_and_undecodable_lines_are_skipped_*
 * | stream passes args without extra flags            | test_extract_stream_posts_*, test_a_file_source_with_options_*
 * | stream throws stderr after yielding, non-zero exit| test_an_error_record_terminates_the_stream_* (records before the failure still arrive)
 * | stream times out when the child goes silent       | test_a_silent_stall_past_the_idle_bound_*
 * | idle timeout spares a slow but productive stream  | test_the_idle_bound_lets_a_long_healthy_stream_*
 *
 * Two further retired cases have no direct HTTP equivalent to re-hold, which
 * is not the same as dropped coverage — each guarded a mechanic that died
 * with the subprocess transport:
 *
 * | Retired CLI case                                  | Why there is no re-hold
 * |---------------------------------------------------|-----------------------------------------------------------
 * | abandoned stream terminates the child             | there is no child; the generator's finally reclaims the curl handles
 * | stream placeholder when stderr is empty           | there is no stderr; the HTTP failure surface is the status and error-record mapping below
 *
 * (The retired idle bound also covered search(); the serve API exposes no
 * route for search, so that half stays parked as bf-4bd.)
 *
 * The harness already has a smoke case for streamed NDJSON
 * (tests/LoopbackServerTest.php); the behavioural coverage — record order,
 * idle-timeout semantics, error mapping, request shape — lives here.
 *
 * Idle-vs-wall-clock distinction: the buffered suites
 * (tests/ClientBufferedRouteTest.php) bound a request with curl's total
 * transfer deadline, so a response that takes longer than the bound fails
 * however productively the server is working. The stream bound is measured
 * from the most recent chunk instead
 * (test_the_idle_bound_lets_a_long_healthy_stream_* proves a stream that
 * runs *past* its bound completes, and
 * test_the_idle_deadline_resets_on_every_chunk_* proves the clock restarts
 * at each record), and the raised TimeoutException names "silence".
 *
 * Every case runs offline: the loopback server answers from scripted
 * responses on an ephemeral 127.0.0.1 port, so nothing here can reach the
 * network beyond loopback. Timing bounds are generous — a stream test
 * asserts a structural bound (a gap the server deliberately inserts) rather
 * than racing a wall clock, and never busy-waits.
 */
#[Group('http-client')]
#[Group('streaming-routes')]
final class ClientStreamingRouteTest extends TestCase
{
    /**
     * Fixture document bytes
     *
     * Deliberately carries a NUL byte and non-ASCII text: the document is
     * uploaded as a multipart part, and the upload must survive both.
     */
    private const PDF_BYTES = "%PDF-1.7\n%\x00streaming-route fixture — naïve bytes\n";

    /**
     * NDJSON records a streamed extraction produces, one per page
     *
     * The stream's unit of delivery is the per-page record rather than the
     * buffered route's whole-document object, so the fixture keeps the
     * documented per-page field names and nesting (models/Page, Span): page
     * indices, list-valued spans, float dimensions, and non-ASCII text. As
     * in the buffered suite, every float deliberately carries a fraction —
     * the harness writes bodies with a plain json_encode, which flattens an
     * integral float (612.0) to an int on the wire.
     */
    private const PAGE_RECORDS = [
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
                    'text' => 'first streamed page — 中文',
                    'bbox' => [72.5, 700.25, 540.5, 712.5],
                    'confidence' => 0.987,
                ],
            ],
        ],
        [
            'page_index' => 1,
            'page_number' => 2,
            'page_label' => 'ii',
            'width' => 612.5,
            'height' => 791.25,
            'rotation' => 90,
            'type' => 'blank',
            'spans' => [],
        ],
        [
            'page_index' => 2,
            'page_number' => 3,
            'page_label' => null,
            'width' => 612.5,
            'height' => 791.25,
            'rotation' => 0,
            'type' => 'text',
            'spans' => [
                [
                    'text' => 'third streamed page',
                    'bbox' => [72.5, 700.25, 540.5, 712.5],
                    'confidence' => 0.954,
                ],
            ],
        ],
        [
            'page_index' => 3,
            'page_number' => 4,
            'page_label' => 'iv',
            'width' => 612.5,
            'height' => 791.25,
            'rotation' => 180,
            'type' => 'text',
            'spans' => [],
        ],
    ];

    /**
     * Options every streamed call may take, and the multipart fields the
     * serve API must then receive
     *
     * The same contract the buffered suite pins (see
     * tests/ClientBufferedRouteTest.php): the stream route takes the same
     * options as the buffered ones — the route differs in how the *response*
     * is delivered, not in what a request may carry. false and null mean
     * "server default" and are dropped, and the client-side 'timeout' option
     * (which becomes the *idle* bound here) is consumed, never forwarded.
     */
    private const FORWARDABLE_OPTIONS = [
        'ocrLanguage' => 'eng',
        'pages' => '1-4',
        'fullRender' => true,
        'noCache' => false,
        'ocrDpi' => null,
        'timeout' => 30,
    ];

    /**
     * What {@see self::FORWARDABLE_OPTIONS} must arrive as on the wire
     */
    private const FORWARDED_FIELDS = [
        'ocr_language' => 'eng',
        'pages' => '1-4',
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

    public function test_extract_stream_posts_the_document_as_multipart_to_the_stream_route(): void
    {
        $this->server->enqueue(
            ScriptedResponse::ndjson('/extract/stream', $this->ndjsonLines(\array_slice(self::PAGE_RECORDS, 0, 2)))
                ->named('stream happy path'),
        );

        $client = new Client($this->server->baseUri(), 'secret-key');

        self::assertSame(
            \array_slice(self::PAGE_RECORDS, 0, 2),
            iterator_to_array($client->extractStream(Source::bytes(self::PDF_BYTES)), false),
        );

        $request = $this->server->lastRequest();
        self::assertNotNull($request, 'the request never reached the loopback server');

        self::assertSame('POST', $request->method(), $request->describe());
        self::assertSame('/extract/stream', $request->path(), $request->describe());
        self::assertSame('', $request->queryString(), $request->describe());
        self::assertSame('Bearer secret-key', $request->authorization(), $request->describe());

        // Same multipart upload contract as the buffered routes: a boundary
        // that is actually present, the document in the serve API's field,
        // the client's fixed filename, PDF media type, and exact bytes. The
        // streamed response shape changes nothing about the request.
        self::assertMatchesRegularExpression(
            '~^multipart/form-data; boundary=\S+~',
            (string)$request->contentType(),
            $request->describe(),
        );
        $this->assertDocumentUpload($request);

        // This is the one route-specific request header: the client asks for
        // NDJSON here and application/json on the buffered routes, so a
        // stream call that reused the buffered Accept would be indistinguishable
        // from one that deliberately negotiated the stream.
        self::assertSame('application/x-ndjson', $request->header('Accept'), $request->describe());

        self::assertSame([], $request->fields(), 'a call without options must send no form fields');
    }

    public function test_a_file_source_with_options_sends_only_the_forwarded_fields_and_the_bytes(): void
    {
        // The buffered suite's field-minimality guarantees, re-held on the
        // stream route: the file path must not leak as a form field or as a
        // query string, the forwarded options must be the only fields, and
        // the upload must carry the file's bytes.
        $path = sys_get_temp_dir() . '/pdftract-streaming-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, self::PDF_BYTES);

        try {
            $this->server->enqueue(
                ScriptedResponse::ndjson('/extract/stream', $this->ndjsonLines([self::PAGE_RECORDS[0]])),
            );

            $client = new Client($this->server->baseUri());
            iterator_to_array(
                $client->extractStream(Source::file($path), self::FORWARDABLE_OPTIONS),
                false,
            );

            $request = $this->server->lastRequest();
            self::assertNotNull($request);

            self::assertSame(self::FORWARDED_FIELDS, $request->fields(), $request->describe());
            self::assertSame('', $request->queryString(), 'neither the source path nor an option may ride the query string');
            $this->assertDocumentUpload($request);
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------------------------- healthy streams

    public function test_a_healthy_stream_yields_each_record_in_order_as_it_arrives(): void
    {
        // Four records spaced 0.4 seconds apart: the server writes the first
        // record, then sleeps, then the second, and so on. If the client
        // buffered the whole body before decoding, the first record could
        // not be observed until the last one had been written — the
        // first-record assertion below fails exactly then. No busy-waiting:
        // the timings are the server's own scripted gaps.
        $this->server->enqueue(
            ScriptedResponse::ndjson('/extract/stream', $this->ndjsonLines(self::PAGE_RECORDS), delaySeconds: 0.4),
        );

        $client = new Client($this->server->baseUri());

        $records = [];
        $firstRecordAt = null;
        $startedAt = microtime(true);

        foreach ($client->extractStream(Source::bytes(self::PDF_BYTES)) as $record) {
            $records[] = $record;
            $firstRecordAt ??= microtime(true) - $startedAt;
        }

        $elapsed = microtime(true) - $startedAt;

        // assertSame, not assertEquals: nested shape, int vs float, nulls and
        // non-ASCII text must survive the NDJSON round trip, and order must
        // be the server's write order.
        self::assertSame(self::PAGE_RECORDS, $records, 'every record must reach the caller, decoded, in order');

        // The first record landed while the server was still between the
        // second and third: incremental delivery, not a buffered decode.
        self::assertNotNull($firstRecordAt);
        self::assertLessThan(0.3, $firstRecordAt, 'the first record must arrive before the stream finishes');

        // And the gaps were really observed: three 0.4s inter-record gaps
        // cannot deliver in less than 1.0s. Only a lower bound, so a slow
        // machine cannot flake this.
        self::assertGreaterThanOrEqual(1.0, $elapsed, 'the server inter-record gaps must reach the client as gaps');
    }

    // ---------------------------------------------------------- idle timeout

    public function test_the_idle_bound_lets_a_long_healthy_stream_complete_where_a_wall_clock_bound_would_cut_it(): void
    {
        // The discriminator between the stream's idle bound and the buffered
        // routes' wall-clock bound: four records 0.4 seconds apart run 1.2
        // seconds in total — half again past the 0.8s bound — but no single
        // gap between chunks gets within a factor of two of it. A
        // CURLOPT_TIMEOUT-style total deadline (the buffered routes' bound)
        // would abort at 0.8s with a partial document; the idle bound, which
        // restarts at every chunk, must let the whole stream finish.
        $records = \array_slice(self::PAGE_RECORDS, 0, 4);

        $this->server->enqueue(
            ScriptedResponse::ndjson('/extract/stream', $this->ndjsonLines($records), delaySeconds: 0.4),
        );

        $client = new Client($this->server->baseUri());

        $startedAt = microtime(true);
        $received = iterator_to_array(
            $client->extractStream(Source::bytes(self::PDF_BYTES), ['timeout' => 0.8]),
            false,
        );
        $elapsed = microtime(true) - $startedAt;

        self::assertSame($records, $received, 'a productive stream must complete despite running past the bound');

        // The stream outlived a bound that a total-deadline transport would
        // have enforced at 0.8s: proof the bound never fired wall-clock.
        self::assertGreaterThanOrEqual(1.0, $elapsed);
    }

    public function test_a_silent_stall_past_the_idle_bound_raises_the_timeout_exception(): void
    {
        // A 5 second stall against a 0.4 second idle bound: the client must
        // give up on the silence, not wait the stall out.
        $this->server->enqueue(ScriptedResponse::stalled('/extract/stream', 5.0));

        $client = new Client($this->server->baseUri(), null, null, 30.0);

        $startedAt = microtime(true);
        $exception = null;

        try {
            iterator_to_array($client->extractStream(Source::bytes(self::PDF_BYTES), ['timeout' => 0.4]), false);
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        $elapsed = microtime(true) - $startedAt;

        self::assertInstanceOf(TimeoutException::class, $exception, 'a stream silent past its bound must raise TimeoutException');
        self::assertSame(0.4, $exception->getTimeoutSeconds(), 'the exception must report the bound it hit');
        self::assertStringContainsString('0.4', $exception->getMessage());
        self::assertStringContainsString($this->server->baseUri(), $exception->getMessage());

        // "Silence" is the idle path's own marker — the buffered routes'
        // total-deadline timeout message does not carry it — so this pins
        // which bound fired, not merely that one did.
        self::assertStringContainsString('silence', $exception->getMessage());
        self::assertNull($exception->getStatusCode(), 'a timeout never produced an HTTP response');
        self::assertNull($exception->getErrorCode());

        self::assertGreaterThan(0.3, $elapsed, 'the abort must respect the bound');
        self::assertLessThan(3.0, $elapsed, 'the client must abort on its bound, not block for the stall');

        // The request still reached the server — the harness records requests
        // even when their response is never read.
        self::assertNotNull($this->server->lastRequest(), 'the timed-out stream request never reached the loopback server');
    }

    public function test_the_idle_deadline_resets_on_every_chunk_so_records_before_a_stall_still_arrive(): void
    {
        // Two records arrive 0.3 seconds apart, then the server goes silent
        // for 5 seconds before a third record the caller must never see. The
        // bound (0.75s) is measured from the *last* chunk: the abort lands
        // at ~1.05s (0.3s of gaps + 0.75s of silence), and everything
        // delivered before the stall is still handed to the caller. Had the
        // deadline been fixed at request start (the buffered semantics), the
        // abort would have landed at ~0.75s — the elapsed floor below
        // separates the two.
        // Chunk delays land *after* their chunk, so the gap between the two
        // records is the first chunk's delay and the post-record silence is
        // the second's. Every body is newline-terminated, as a real record
        // line would be.
        $chunks = $this->ndjsonChunks([self::PAGE_RECORDS[0], self::PAGE_RECORDS[1]]);
        $chunks[0]['delaySeconds'] = 0.3;
        $chunks[1]['delaySeconds'] = 5.0;
        $chunks[] = ['body' => $this->recordLine(self::PAGE_RECORDS[2]) . "\n", 'delaySeconds' => 0.0];

        $this->server->enqueue(
            ScriptedResponse::chunked('/extract/stream', $chunks),
        );

        $client = new Client($this->server->baseUri());

        $records = [];
        $startedAt = microtime(true);
        $exception = null;

        try {
            foreach ($client->extractStream(Source::bytes(self::PDF_BYTES), ['timeout' => 0.75]) as $record) {
                $records[] = $record;
            }
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        $elapsed = microtime(true) - $startedAt;

        self::assertInstanceOf(TimeoutException::class, $exception, 'the post-record silence must raise TimeoutException');
        self::assertSame(0.75, $exception->getTimeoutSeconds());

        // Records that arrived before the stall reached the caller; the
        // record behind the stall never did.
        self::assertSame(
            [self::PAGE_RECORDS[0], self::PAGE_RECORDS[1]],
            $records,
            'records delivered before the stall must arrive; the stalled record must not',
        );

        // Structurally ≥ gaps + bound under reset-on-every-chunk semantics
        // (~1.05s here); a start-anchored deadline would abort at ~0.75s.
        self::assertGreaterThanOrEqual(1.0, $elapsed, 'the deadline must restart at the last chunk, not at the request');
        self::assertLessThan(4.0, $elapsed, 'the client must abort inside the silence, not wait it out');
    }

    public function test_the_constructor_default_supplies_the_idle_bound_without_a_per_call_override(): void
    {
        $this->server->enqueue(ScriptedResponse::stalled('/extract/stream', 5.0));

        $client = new Client($this->server->baseUri(), null, null, 0.4);

        $exception = null;

        try {
            iterator_to_array($client->extractStream(Source::bytes(self::PDF_BYTES)), false);
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(TimeoutException::class, $exception, 'the constructor default must bound a stream too');
        self::assertSame(0.4, $exception->getTimeoutSeconds());
    }

    public function test_a_zero_timeout_disables_the_idle_bound(): void
    {
        // The 0.25 second constructor default would cut the first 0.35 second
        // inter-record gap off; the per-call 0 removes the bound entirely, so
        // the whole spaced-out stream completes.
        $this->server->enqueue(
            ScriptedResponse::ndjson('/extract/stream', $this->ndjsonLines(\array_slice(self::PAGE_RECORDS, 0, 3)), 0.35),
        );

        $client = new Client($this->server->baseUri(), null, null, 0.25);

        self::assertSame(
            \array_slice(self::PAGE_RECORDS, 0, 3),
            iterator_to_array($client->extractStream(Source::bytes(self::PDF_BYTES), ['timeout' => 0]), false),
        );
    }

    // ------------------------------------------------------------ error paths

    public function test_an_error_record_terminates_the_stream_as_the_documented_exception(): void
    {
        // The serve API reports a mid-extraction failure as a final
        // {"error": ...} line. It must raise instead of arriving as a
        // record — and everything streamed before it must already have
        // reached the caller.
        $this->server->enqueue(
            ScriptedResponse::ndjson('/extract/stream', [
                $this->recordLine(self::PAGE_RECORDS[0]),
                json_encode(['error' => 'ocr worker died on page 2'], JSON_THROW_ON_ERROR),
                $this->recordLine(self::PAGE_RECORDS[2]),
            ]),
        );

        $client = new Client($this->server->baseUri());

        $records = [];
        $exception = null;

        try {
            foreach ($client->extractStream(Source::bytes(self::PDF_BYTES)) as $record) {
                $records[] = $record;
            }
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'an error record must terminate the stream with an exception');
        self::assertInstanceOf(PdftractException::class, $exception);
        self::assertStringContainsString('ocr worker died on page 2', $exception->getMessage());
        self::assertNull($exception->getStatusCode(), 'an error record is not an HTTP failure');

        // The pre-error record arrived; neither the error line itself nor the
        // record scripted after it did.
        self::assertSame([self::PAGE_RECORDS[0]], $records);
    }

    #[DataProvider('provideStreamRejectedBeforeTheBody')]
    public function test_a_non_2xx_status_before_the_body_maps_to_the_documented_exception(
        int $status,
        string $errorCode,
        string $message,
        ?string $hint,
    ): void {
        $this->server->enqueue(
            ScriptedResponse::error('/extract/stream', $status, $errorCode, $message, $hint),
        );

        $client = new Client($this->server->baseUri());

        $records = [];
        $exception = null;

        try {
            foreach ($client->extractStream(Source::bytes(self::PDF_BYTES)) as $record) {
                $records[] = $record;
            }
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'a non-2xx status must raise PdftractException on the stream route too');
        self::assertSame($status, $exception->getStatusCode(), 'the HTTP status must be preserved');
        self::assertSame($status, $exception->getCode(), 'the exception code carries the HTTP status');
        self::assertSame($errorCode, $exception->getErrorCode(), 'the serve API error code must be preserved');
        self::assertStringContainsString($message, $exception->getMessage());

        if ($hint !== null) {
            self::assertSame($hint, $exception->getHint(), 'the server hint must be preserved');
            self::assertStringContainsString($hint, $exception->getMessage(), 'the hint should surface to the caller');
        }

        // The rejection arrived before any NDJSON body, so no record can
        // have reached the caller.
        self::assertSame([], $records, 'no record may be yielded from a request the server rejected');
    }

    public static function provideStreamRejectedBeforeTheBody(): array
    {
        return [
            '400 validation' => [400, 'INVALID_REQUEST', 'pages option is not a page range', 'use N or N-M'],
            '500 server error' => [500, 'INTERNAL_ERROR', 'the extractor crashed on page 7', null],
        ];
    }

    public function test_a_foreign_error_body_on_the_stream_route_is_excerpted(): void
    {
        // A reverse proxy interposing its own error page: the body is not
        // the serve API's {error, message} shape, so the client reports the
        // status and excerpts the raw body instead of inventing fields —
        // and must not mistake an HTML page for NDJSON records.
        $body = '<html><body>502 Bad Gateway</body></html>';

        $this->server->enqueue(
            ScriptedResponse::text($body, '/extract/stream')->withStatus(502)->named('proxy error page'),
        );

        $client = new Client($this->server->baseUri());

        $exception = null;

        try {
            iterator_to_array($client->extractStream(Source::bytes(self::PDF_BYTES)), false);
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'a non-2xx stream response must raise even without an error body');
        self::assertSame(502, $exception->getStatusCode());
        self::assertNull($exception->getErrorCode(), 'no serve-API error code can be read from a foreign body');
        self::assertStringContainsString('502', $exception->getMessage());
        self::assertStringContainsString($body, $exception->getMessage(), 'the raw body should be excerpted');
    }

    public function test_a_connection_drop_mid_stream_surfaces_as_a_connection_exception(): void
    {
        // The server promises a body longer than it sends and the connection
        // ends: the model of a mid-stream drop. The loopback cli-server
        // frames streamed bodies as close-delimited (no Content-Length of
        // its own), so a bare process kill would be indistinguishable from a
        // clean end-of-stream; a declared length that the bytes fall short
        // of is what lets the transport know data went missing, which is
        // exactly what a dropped connection looks like to curl whenever the
        // peer had more to say. The failure must surface as a typed
        // transport exception, never as a quietly short stream.
        $records = \array_slice(self::PAGE_RECORDS, 0, 2);
        $body = implode('', $this->ndjsonLines($records));
        $declared = strlen($body) + 500;

        $this->server->enqueue(
            ScriptedResponse::chunked('/extract/stream', $this->ndjsonChunks($records))
                ->withHeader('Content-Length', (string)$declared)
                ->named('stream cut short'),
        );

        $client = new Client($this->server->baseUri());

        $received = [];
        $exception = null;

        try {
            foreach ($client->extractStream(Source::bytes(self::PDF_BYTES), ['timeout' => 0]) as $record) {
                $received[] = $record;
            }
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'a stream that ends short of its declared body must not look complete');
        self::assertInstanceOf(ConnectionException::class, $exception);
        self::assertNotSame('', $exception->getReason(), 'the underlying transport error should be carried');
        self::assertStringContainsString($this->server->baseUri(), $exception->getMessage());
        self::assertNull($exception->getStatusCode(), 'the transfer never produced a failing HTTP status');
        self::assertNull($exception->getErrorCode());

        // What arrived before the drop still reached the caller: the
        // exception reports the loss instead of retroactively hiding it.
        self::assertSame($records, $received);
    }

    // ------------------------------------------------- malformed NDJSON lines

    public function test_blank_and_undecodable_lines_are_skipped_and_the_records_around_them_survive(): void
    {
        // The documented line contract (Client::extractStream()): blank and
        // undecodable lines are skipped, because a stream is resumable
        // line-by-line — one garbled line must not discard the records
        // before or after it, the way an undecodable whole body does on the
        // buffered routes. The garbled shapes here are the ones real
        // corruption produces: a truncated line, a scalar, a JSON string,
        // and a blank line (say, a heartbeat separator).
        $this->server->enqueue(
            ScriptedResponse::ndjson('/extract/stream', [
                $this->recordLine(self::PAGE_RECORDS[0]),
                '{"page_index": 1, "trunc',
                '',
                '42',
                '"just a string"',
                'null',
                $this->recordLine(self::PAGE_RECORDS[1]),
            ]),
        );

        $client = new Client($this->server->baseUri());

        self::assertSame(
            [self::PAGE_RECORDS[0], self::PAGE_RECORDS[1]],
            iterator_to_array($client->extractStream(Source::bytes(self::PDF_BYTES)), false),
            'the records either side of the undecodable lines must survive them',
        );
    }

    public function test_a_record_split_across_chunks_and_a_final_line_without_a_newline_are_parsed(): void
    {
        // The client reassembles lines across chunk boundaries and yields a
        // trailing record that arrives without its terminating newline —
        // the two parsing paths a chunked transport exercises that a
        // line-per-read transport never meets.
        $this->server->enqueue(
            ScriptedResponse::chunked('/extract/stream', [
                ['body' => '{"page_index": 0, "split_across_ch'],
                ['body' => 'unks": true, "page_number": 1}' . "\n"],
                ['body' => $this->recordLine(self::PAGE_RECORDS[1])], // no trailing newline
            ]),
        );

        $client = new Client($this->server->baseUri());

        self::assertSame(
            [
                ['page_index' => 0, 'split_across_chunks' => true, 'page_number' => 1],
                self::PAGE_RECORDS[1],
            ],
            iterator_to_array($client->extractStream(Source::bytes(self::PDF_BYTES)), false),
        );
    }

    // --------------------------------------------------------------- helpers

    /**
     * Encode records as NDJSON lines (without their newlines)
     *
     * @param list<array<string, mixed>> $records
     * @return list<string>
     */
    private function ndjsonLines(array $records): array
    {
        return array_map($this->recordLine(...), $records);
    }

    /**
     * Encode records as newline-terminated chunk bodies
     *
     * @param list<array<string, mixed>> $records
     * @return list<array{body: string, delaySeconds: float}>
     */
    private function ndjsonChunks(array $records): array
    {
        return array_map(
            static fn (string $line): array => ['body' => $line . "\n", 'delaySeconds' => 0.0],
            $this->ndjsonLines($records),
        );
    }

    /**
     * Encode one record as a single NDJSON line
     *
     * @param array<string, mixed> $record
     */
    private function recordLine(array $record): string
    {
        return json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Assert the request uploaded the fixture document the way the serve API
     * reads it: one part, in the documented field, with the client's fixed
     * filename, PDF media type, and the exact bytes it was given — the same
     * multipart contract the buffered routes are held to
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
