<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use Jedarden\Pdftract\Client;
use Jedarden\Pdftract\PdftractException;
use Jedarden\Pdftract\Source;
use Jedarden\Pdftract\TimeoutException;
use Jedarden\Pdftract\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Real-server integration for the canonical HTTP client: the routes of a
 * live `pdftract --serve` (axum) process — the three POST routes driven
 * end to end through {@see Client}, and the two GET routes pinned at the
 * wire (the root banner and the /extract file-path guard have no client
 * surface: the client is POST-only) — with no fixture in between.
 *
 * The suite closes the gap between the two loops the rest of the coverage
 * lives in, which never meet: the loopback fixture harness
 * (tests/LoopbackServerTest.php and the buffered/streaming suites it feeds)
 * scripts every response in-process, and the binary conformance harness
 * (tests/ClientBinaryConformanceTest.php, group binary-conformance)
 * exercises the retired CLI-subprocess transport. Against the fixture
 * harness, multipart parsing quirks, real NDJSON chunk framing, actual
 * header/status behaviour, and the Addendum 2026-09-27 route findings are
 * pinned against a PHP fake; here they are pinned against the server
 * itself. ClientServeParityTest (group serve-parity) also spawns a real
 * server, but its client layer exists only to mirror the CLI's output —
 * this suite is where the client's own contract against the real wire
 * lives, cross-checked against the expectations the fixture-harness suites
 * pin (the same {error, message, hint} body shapes, content types, and
 * status→exception mapping those suites' providers carry, aligned to serve
 * in docs/notes/serve-parity-gap.md, Addendum 2026-09-27d).
 *
 * Method: for each route the suite POSTs the same vendored fixture twice —
 * once as a raw multipart upload with no SDK (the wire's own answer), once
 * through {@see Client} — and asserts the client's result or exception
 * carries the wire's verdict field for field, in the shape the fixture
 * harness pins. At the pinned upstream revision (eeab77e) extraction fails
 * for every document, so the failure branches below are the ones that run;
 * the success branches are the forward pin and light up automatically
 * against any binary that can actually extract. Both branches assert, so
 * neither run is empty.
 *
 * The binary comes from the environment:
 *
 * - PDFTRACT_SERVE_BIN — the same variable the serve-parity suite reads:
 *   one pdftract binary whose `serve` subcommand is exercised here. Unset:
 *   every test skips with a message (the PDFTRACT_BIN harness's clean-skip
 *   semantics, bead pdfphp-384ac455), so CI and clean-clone runs stay green
 *   without a build. Set but not an executable file: that is a harness
 *   misconfiguration and fails, exactly as the other binary-gated suites
 *   treat it.
 * - The stock conformance binaries the build script produces
 *   (pdftract-default / pdftract-full, bead pdfphp-08e4c83c) start and
 *   print their banner but then answer HTTP 500 on every request — the
 *   audit middleware's ConnectInfo extractor panics (docs/notes/
 *   serve-parity-gap.md, Addendum 2026-09-27c) — so a binary whose serve
 *   surface is broken is detected here and skipped loudly rather than
 *   failed: it is the "no feature-complete binary available" case, and
 *   failing CI on a documented upstream defect would keep every run red.
 *   A serve-capable build — the probe build that addendum describes — runs
 *   the whole suite green.
 *
 * The serve lifecycle is owned here, as in ClientServeParityTest: one
 * instance per process, bound to an ephemeral 127.0.0.1 port with
 * --no-cache, polled healthy before any assertion, terminated and reaped in
 * tearDownAfterClass AND in a shutdown function, so a failing run cannot
 * orphan it. Nothing here reaches beyond loopback.
 *
 * Idle-bound behaviour is pinned on both sides of the bound. The healthy
 * side proves the bound does not misfire against a real server's latency
 * (a full request/response round trip completes inside a generous bound —
 * observed stream answers land at 0.2–1.6 ms). The silent side needs the
 * server to stop talking, which no revision under test does on its own —
 * scripting stalls is the fixture harness's job (ScriptedResponse::stalled)
 * — so it is probed with a bound (a microsecond) no round trip can meet:
 * expiry is decided client-side, before the fastest observed real answer
 * could arrive, and the assertion is that the real server's silence between
 * request and response is what the client's idle clock measures. The
 * buffered routes' wall-clock bound has no such deterministic real-server
 * pin: curl's millisecond-granularity deadline loses the race against a
 * sub-millisecond real answer, so that machinery stays fixture-harness
 * territory.
 */
#[Group('real-server')]
final class ClientRealServerTest extends TestCase
{
    /** Environment variable carrying the pdftract binary under test. */
    private const BIN_ENV = 'PDFTRACT_SERVE_BIN';

    private const FIXTURES_PATH = __DIR__ . '/sdk-conformance/fixtures/';

    /**
     * The two failure classes the vendored fixtures divide into at the
     * pinned revision (Addendum 2026-09-27): a catalog that parses but
     * whose page iteration ends empty, and a catalog-level defect that
     * fails before pages. One fixture from each class.
     */
    private const FIXTURE_INVOICE = 'invoice/01.pdf';

    private const FIXTURE_CORRUPT = 'broken/corrupt.pdf';

    /** Uploads the real multipart parser must reject before extraction. */
    private const NOT_A_PDF_BYTES = 'This is plain text, definitely not a PDF document.';

    private const TOO_SMALL_BYTES = '%PD';

    /** Seconds to wait for GET /health to answer before giving up on the binary. */
    private const HEALTH_TIMEOUT_SECONDS = 15.0;

    /** Wall-clock bound for raw probes and buffered client calls. Extraction fails fast; this only bounds pathology. */
    private const CLIENT_TIMEOUT_SECONDS = 30.0;

    /** A generous idle bound a healthy real stream must complete inside. */
    private const IDLE_BOUND_SECONDS = 5.0;

    /**
     * An unmeetable idle bound for the silent-side probe: several hundred
     * times below the fastest round trip the real server was observed to
     * answer (~0.2 ms), so the bound always expires while the server is
     * still thinking.
     */
    private const IDLE_PROBE_SECONDS = 0.000001;

    private static string $binary = '';

    private static string $baseUrl = '';

    /** @var resource|null proc_open handle of the serve process */
    private static $serveProcess = null;

    private static bool $serveTerminated = false;

    /** @var array{status: string, version: string}|null the decoded GET /health banner */
    private static ?array $healthBanner = null;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('The curl extension is required for the HTTP client.');
        }

        $binary = getenv(self::BIN_ENV);

        if (!is_string($binary) || trim($binary) === '') {
            self::markTestSkipped(
                self::BIN_ENV . ' is not set — this suite runs the canonical HTTP client'
                . ' against a live `pdftract --serve` process and so needs a serve-capable'
                . ' pdftract binary. Point the variable at one and the suite runs; without'
                . ' it every test skips, keeping plain runs green. The stock conformance'
                . ' binaries from scripts/build-conformance-binaries.sh will not do at the'
                . ' pinned revision — their serve surface answers 500 on every request'
                . ' (docs/notes/serve-parity-gap.md, Addendum 2026-09-27c); the probe build'
                . ' described there runs this suite green.'
            );
        }

        self::$binary = $binary;

        if (!is_file(self::$binary) || !is_executable(self::$binary)) {
            self::fail(
                self::BIN_ENV . ' is set but not an executable file: ' . self::$binary
            );
        }

        self::startServe();
        register_shutdown_function(static function (): void {
            self::stopServe();
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServe();
    }

    // ------------------------------------------------------------- the server

    public function test_the_real_server_answers_health_with_the_documented_banner(): void
    {
        // Pinned from the gate's own probe: the server that answered is
        // really pdftract, described in its own words.
        self::assertIsArray(self::$healthBanner, 'the gate must have captured the health banner');
        self::assertSame('ok', self::$healthBanner['status'] ?? null);
        self::assertIsString(self::$healthBanner['version'] ?? null);
        self::assertNotSame('', self::$healthBanner['version']);
    }

    // -------------------------------------------------------------- GET routes

    public function test_the_root_route_answers_the_documented_route_banner(): void
    {
        // GET / is the server's self-description (serve.rs root_handler):
        // a fixed 200 JSON banner advertising exactly the three POST
        // routes and /health — the same list the parity matrix
        // inventories. Pinned per field so a route that is dropped,
        // renamed, or silently unadvertised fails here first; the only
        // dynamic field, `version`, is cross-checked against the /health
        // banner the startup gate captured (both read the binary's own
        // package version).
        $wire = $this->getWire('/');

        self::assertSame(200, $wire['status'], 'the root banner is a fixed 200 — it has no failure mode');
        self::assertSame('application/json', $wire['contentType'], $wire['body']);

        $body = self::decodeJsonObject('/', $wire['body']);

        self::assertSame('pdftract', $body['service'] ?? null, $wire['body']);
        self::assertIsString($body['version'] ?? null, $wire['body']);
        self::assertNotSame('', $body['version'], $wire['body']);
        self::assertSame(
            self::$healthBanner['version'] ?? null,
            $body['version'],
            'the banner and /health describe the same binary version',
        );
        self::assertSame(
            [
                'POST /extract - Extract PDF and return JSON',
                'POST /extract/text - Extract PDF and return plain text',
                'POST /extract/stream - Extract PDF and return streaming NDJSON',
                'GET /health - Health check',
            ],
            $body['endpoints'] ?? null,
            "the banner must advertise exactly the documented routes, in the server's own order",
        );
    }

    public function test_the_get_extract_guard_rejects_file_path_queries_with_the_only_json_404(): void
    {
        // The guard's reason to exist: a file path must never ride a
        // query string (?path=/etc/passwd and friends), so GET /extract
        // is answered by extract_get_not_found_handler with the serve
        // API's only JSON 404. The query string is not even read (the
        // handler takes no query extractor), and a dropped registration
        // would fall through to axum's bodyless 404 instead — so both
        // halves are load-bearing: the verbatim JSON body under a
        // file-path query, and its byte-identity with the bare-GET
        // answer, pinning that the guard fires on the method and ignores
        // the parameters wholesale.
        $queried = $this->getWire('/extract?path=/etc/passwd');
        $bare = $this->getWire('/extract');

        self::assertSame(404, $queried['status'], 'the file-path guard must answer 404');
        self::assertSame('application/json', $queried['contentType'], $queried['body']);

        $body = self::decodeJsonObject('/extract', $queried['body']);

        self::assertSame('NOT_FOUND', $body['error'] ?? null, $queried['body']);
        self::assertSame(
            'POST to /extract with multipart/form-data is required; file-path parameters are not supported',
            $body['message'] ?? null,
            $queried['body'],
        );
        self::assertSame(
            "Use POST /extract with a 'file' field containing the PDF bytes",
            $body['hint'] ?? null,
            $queried['body'],
        );

        self::assertSame(404, $bare['status'], 'the guard fires without a query string too');
        self::assertSame($queried['body'], $bare['body'], 'the query string must be ignored wholesale, not parsed');
    }

    // -------------------------------------------------------- buffered routes

    public function test_extract_carries_the_wire_rejection_with_its_fields_unchanged(): void
    {
        $fixturePath = self::FIXTURES_PATH . self::FIXTURE_INVOICE;
        $this->assertFileExists($fixturePath, 'Vendored fixture missing: ' . self::FIXTURE_INVOICE);

        $wire = $this->postMultipart('/extract', $fixturePath);
        [$result, $exception] = $this->runClient(
            fn (): array => (new Client(self::$baseUrl, timeoutSeconds: self::CLIENT_TIMEOUT_SECONDS))
                ->extract(Source::file($fixturePath), ['timeout' => self::CLIENT_TIMEOUT_SECONDS]),
        );

        if ($wire['status'] < 200 || $wire['status'] >= 300) {
            $this->assertWireRejectionIsMappedVerbatim('/extract', $wire, $exception);

            return;
        }

        // Success domain — the forward pin. When a binary that can extract
        // is supplied, the client's array must be the wire body's faithful
        // decode, the same round trip the buffered suite holds against the
        // harness (test_extract_maps_the_json_body_to_the_documented_php_value).
        $this->assertNull($exception, 'the client must not raise when the wire answers 2xx');
        self::assertSame(self::decodeJsonObject('/extract', $wire['body']), $result);
    }

    public function test_extract_text_maps_the_wire_rejection_the_same_way(): void
    {
        // The second failure class, on the second buffered route: same
        // shared receive_pdf/build_options machinery, same body shape, and
        // (per the buffered suite's text-route pin,
        // test_extract_text_maps_a_server_error_to_the_documented_exception)
        // the same exception mapping.
        $fixturePath = self::FIXTURES_PATH . self::FIXTURE_CORRUPT;
        $this->assertFileExists($fixturePath, 'Vendored fixture missing: ' . self::FIXTURE_CORRUPT);

        $wire = $this->postMultipart('/extract/text', $fixturePath);
        [$result, $exception] = $this->runClient(
            fn (): string => (new Client(self::$baseUrl, timeoutSeconds: self::CLIENT_TIMEOUT_SECONDS))
                ->extractText(Source::file($fixturePath), ['timeout' => self::CLIENT_TIMEOUT_SECONDS]),
        );

        if ($wire['status'] < 200 || $wire['status'] >= 300) {
            $this->assertWireRejectionIsMappedVerbatim('/extract/text', $wire, $exception);

            return;
        }

        // Forward pin: the text route returns the body verbatim
        // (test_extract_text_returns_the_body_verbatim in the buffered suite).
        $this->assertNull($exception, 'the client must not raise when the wire answers 2xx');
        self::assertSame($wire['body'], $result);
    }

    #[DataProvider('provideNonPdfUploads')]
    public function test_the_real_multipart_parser_rejects_an_upload_that_is_not_a_pdf(
        string $bytes,
        string $expectedMessage,
    ): void {
        // The pre-extraction rejection family, through the real axum
        // multipart parser: bytes without the %PDF- magic (or under five of
        // them) are refused with a 400 BAD_REQUEST body before any
        // extraction is attempted. The harness pins these rows as
        // serve-produced shapes (Addendum 2026-09-27d); here the real wire
        // must answer exactly them, and the client must carry them through.
        $wire = $this->postMultipartBytes('/extract', $bytes);
        [, $exception] = $this->runClient(
            fn (): array => (new Client(self::$baseUrl, timeoutSeconds: self::CLIENT_TIMEOUT_SECONDS))
                ->extract(Source::bytes($bytes), ['timeout' => self::CLIENT_TIMEOUT_SECONDS]),
        );

        $body = self::decodeJsonObject('/extract', $wire['body']);

        self::assertSame(400, $wire['status'], 'a magic-less upload must be refused before extraction');
        self::assertSame('application/json', $wire['contentType'], $wire['body']);
        self::assertSame('BAD_REQUEST', $body['error'] ?? null, $wire['body']);
        self::assertSame(
            $expectedMessage,
            $body['message'] ?? null,
            'the real server\'s refusal text must match the row the fixture harness pins',
        );
        self::assertSame(
            'Upload a valid PDF file (must start with %PDF-)',
            $body['hint'] ?? null,
            $wire['body'],
        );

        self::assertNotNull($exception, 'a 400 must raise PdftractException');
        self::assertSame(ValidationException::class, get_class($exception), 'the status alone picks the class');
        self::assertSame(400, $exception->getStatusCode());
        self::assertSame(400, $exception->getCode());
        self::assertSame('BAD_REQUEST', $exception->getErrorCode());
        self::assertSame(
            $expectedMessage . ' (hint: Upload a valid PDF file (must start with %PDF-))',
            $exception->getMessage(),
            'the refusal must surface verbatim, with the hint composed in',
        );
        self::assertSame('Upload a valid PDF file (must start with %PDF-)', $exception->getHint());
    }

    public static function provideNonPdfUploads(): array
    {
        // The two refusal texts the magic-byte check produces, in the order
        // receive_pdf applies it: size first, then magic (serve.rs:341-352).
        return [
            'under five bytes' => [self::TOO_SMALL_BYTES, 'Uploaded file is too small to be a valid PDF'],
            'bytes without the magic' => [self::NOT_A_PDF_BYTES, 'Uploaded file is not a PDF (missing %PDF- header)'],
        ];
    }

    public function test_the_stream_route_shares_the_real_upload_rejection(): void
    {
        // receive_pdf() is called by all three POST handlers, so the stream
        // route must refuse the same upload with the same 400 the buffered
        // route answered — and the client must raise it before any record.
        $wire = $this->postMultipartBytes('/extract/stream', self::NOT_A_PDF_BYTES);
        $records = [];
        [, $exception] = $this->runClient(function () use (&$records): array {
            $client = new Client(self::$baseUrl, timeoutSeconds: self::CLIENT_TIMEOUT_SECONDS);

            foreach ($client->extractStream(Source::bytes(self::NOT_A_PDF_BYTES), ['timeout' => self::CLIENT_TIMEOUT_SECONDS]) as $record) {
                $records[] = $record;
            }

            return $records;
        });

        $body = self::decodeJsonObject('/extract/stream', $wire['body']);

        self::assertSame(400, $wire['status'], 'the stream route must refuse a magic-less upload identically');
        self::assertSame('BAD_REQUEST', $body['error'] ?? null, $wire['body']);
        self::assertSame(
            'Uploaded file is not a PDF (missing %PDF- header)',
            $body['message'] ?? null,
            'the shared rejection must carry the same text on every POST route',
        );

        self::assertNotNull($exception, 'a 400 must raise PdftractException on the stream route too');
        self::assertSame(ValidationException::class, get_class($exception));
        self::assertSame(400, $exception->getStatusCode());
        self::assertSame('BAD_REQUEST', $exception->getErrorCode());
        self::assertSame(
            'Uploaded file is not a PDF (missing %PDF- header) (hint: Upload a valid PDF file (must start with %PDF-))',
            $exception->getMessage(),
        );
        self::assertSame([], $records, 'the rejection arrived before the body — no record can be yielded');
    }

    public function test_a_configured_api_key_travels_inert_past_the_real_server(): void
    {
        // The serve API has no auth of its own (serve.rs:6-17): the
        // Authorization: Bearer header the client sends for a configured key
        // is a proxy contract, and the real server must neither honour nor
        // trip over it. Whatever the wire's verdict for the keyless client,
        // the keyed client must receive exactly the same one.
        $fixturePath = self::FIXTURES_PATH . self::FIXTURE_INVOICE;
        $this->assertFileExists($fixturePath, 'Vendored fixture missing: ' . self::FIXTURE_INVOICE);

        $keyless = $this->runClient(
            fn (): array => (new Client(self::$baseUrl, timeoutSeconds: self::CLIENT_TIMEOUT_SECONDS))
                ->extract(Source::file($fixturePath), ['timeout' => self::CLIENT_TIMEOUT_SECONDS]),
        );
        $keyed = $this->runClient(
            fn (): array => (new Client(self::$baseUrl, 'proxy-key-real-server-suite', timeoutSeconds: self::CLIENT_TIMEOUT_SECONDS))
                ->extract(Source::file($fixturePath), ['timeout' => self::CLIENT_TIMEOUT_SECONDS]),
        );

        self::assertSame($keyless[0] !== null, $keyed[0] !== null, 'the key must not flip success to failure or back');

        if ($keyless[1] !== null) {
            self::assertInstanceOf(PdftractException::class, $keyed[1]);
            self::assertSame(get_class($keyless[1]), get_class($keyed[1]), 'the key must not change the exception class');
            self::assertSame($keyless[1]->getStatusCode(), $keyed[1]->getStatusCode());
            self::assertSame($keyless[1]->getErrorCode(), $keyed[1]->getErrorCode());
            self::assertSame($keyless[1]->getMessage(), $keyed[1]->getMessage(), 'the key must not change the server\'s verdict');

            return;
        }

        self::assertSame($keyless[0], $keyed[0], 'the key must not change a successful response');
    }

    // --------------------------------------------------------- streaming route

    public function test_extract_stream_surfaces_the_wire_in_band_error_verbatim(): void
    {
        // The stream route's failure domain — the one place the POST routes
        // disagree (Addendum 2026-09-27): the axum handler commits a 200
        // application/x-ndjson response before extraction starts, so the
        // failure travels in-band as a final newline-terminated
        // {"error": ...} record carrying no `message`, no code, and no
        // hint. The client must raise it on the base class with no status
        // and no error code (decodeRecord), exactly as the streaming suite
        // pins against the harness's copy of this very event
        // (test_the_serve_mid_extraction_error_event_is_surfaced_verbatim) —
        // except the record here is the server's own, not a scripted copy.
        $fixturePath = self::FIXTURES_PATH . self::FIXTURE_INVOICE;
        $this->assertFileExists($fixturePath, 'Vendored fixture missing: ' . self::FIXTURE_INVOICE);

        $wire = $this->postMultipart('/extract/stream', $fixturePath);
        $records = [];
        [, $exception] = $this->runClient(function () use (&$records, $fixturePath): array {
            $client = new Client(self::$baseUrl, timeoutSeconds: self::CLIENT_TIMEOUT_SECONDS);

            foreach ($client->extractStream(Source::file($fixturePath), ['timeout' => self::CLIENT_TIMEOUT_SECONDS]) as $record) {
                $records[] = $record;
            }

            return $records;
        });

        // The stream route answers 200 in both domains — the handler
        // commits the response before extraction runs — so the status and
        // the NDJSON framing are pinned unconditionally: real chunk
        // framing, newline-terminated lines, the NDJSON content type the
        // harness's ScriptedResponse::ndjson() imitates.
        self::assertSame(200, $wire['status'], 'the stream route commits its response before extraction');
        self::assertSame('application/x-ndjson', $wire['contentType'], $wire['body']);
        self::assertStringEndsWith("\n", $wire['body'], 'every stream body must be newline-terminated');

        $lines = array_values(array_filter(explode("\n", $wire['body']), static fn (string $line) => $line !== ''));
        $wireRecords = array_map(
            static fn (string $line): array => self::decodeJsonObject('/extract/stream', $line),
            $lines,
        );

        $errorRecord = null;

        foreach ($wireRecords as $wireRecord) {
            if (is_string($wireRecord['error'] ?? null)) {
                $errorRecord = $wireRecord;
                break;
            }
        }

        if ($errorRecord === null) {
            // Success domain — the forward pin. Every streamed record must
            // reach the caller decoded, in the server's write order.
            self::assertSame($wireRecords, $records, 'the client must yield the wire\'s records decoded, in order');
            self::assertNull($exception, 'no record carried an error, so the client must not raise');

            return;
        }

        self::assertArrayNotHasKey(
            'message',
            $errorRecord,
            'stream error records carry no `message` field — buffered-shape bleed-through',
        );

        self::assertSame([], $records, 'the error record is the only record — nothing may reach the caller before it raises');
        self::assertNotNull($exception, 'the in-band error record must terminate the stream with an exception');
        self::assertSame(PdftractException::class, get_class($exception), 'an in-band record must NOT surface as a status-mapped subclass');
        self::assertSame($errorRecord['error'], $exception->getMessage(), 'the record\'s error must surface byte-identical');
        self::assertNull($exception->getStatusCode(), 'the stream answered 200 — no HTTP status failed');
        self::assertNull($exception->getErrorCode(), 'the in-band record carries no error code');
        self::assertNull($exception->getHint(), 'the in-band record carries no hint');
    }

    // ------------------------------------------------------------------ idle bound

    public function test_a_healthy_real_stream_completes_inside_a_generous_idle_bound(): void
    {
        // The healthy side of the idle bound against a real server: a full
        // request → multipart parse → extraction → response round trip must
        // run to its verdict inside a generous bound, whatever that verdict
        // is. A bound that misfired on real latency would cut the stream
        // off with a TimeoutException before the server's answer arrived.
        $fixturePath = self::FIXTURES_PATH . self::FIXTURE_INVOICE;

        $records = [];
        [, $exception] = $this->runClient(function () use (&$records, $fixturePath): array {
            $client = new Client(self::$baseUrl, timeoutSeconds: self::CLIENT_TIMEOUT_SECONDS);

            foreach ($client->extractStream(Source::file($fixturePath), ['timeout' => self::IDLE_BOUND_SECONDS]) as $record) {
                $records[] = $record;
            }

            return $records;
        });

        self::assertNotInstanceOf(
            TimeoutException::class,
            $exception,
            'the idle bound must not fire on a productive real stream',
        );

        // The bound was not the thing that ended the stream: at the pinned
        // revision the in-band error record did (asserted by its own test
        // above); against a succeeding binary the records will. Either way
        // the round trip itself completed.
        if ($exception !== null) {
            self::assertSame(PdftractException::class, get_class($exception));
            self::assertSame([], $records);
        }
    }

    public function test_a_real_stream_silent_past_its_idle_bound_raises_the_timeout_exception(): void
    {
        // The silent side of the idle bound, against the real server's own
        // silence: the span between the request leaving and the server's
        // first byte arriving is silence the client's idle clock measures,
        // and a bound no round trip can meet ({@see self::IDLE_PROBE_SECONDS})
        // must expire inside it — long before the fastest observed real
        // answer (~0.2 ms) could arrive. The exception is the documented
        // one: TimeoutException naming the silence and carrying the bound,
        // with no HTTP status (nothing failed at the HTTP layer) and no
        // records (the abort beat the body).
        $fixturePath = self::FIXTURES_PATH . self::FIXTURE_INVOICE;

        $startedAt = microtime(true);
        $records = [];
        [, $exception] = $this->runClient(function () use (&$records, $fixturePath): array {
            $client = new Client(self::$baseUrl, timeoutSeconds: self::CLIENT_TIMEOUT_SECONDS);

            foreach ($client->extractStream(Source::file($fixturePath), ['timeout' => self::IDLE_PROBE_SECONDS]) as $record) {
                $records[] = $record;
            }

            return $records;
        });
        $elapsed = microtime(true) - $startedAt;

        self::assertNotNull($exception, 'a stream silent past its bound must raise');
        self::assertInstanceOf(TimeoutException::class, $exception, 'the idle bound must fire, not the server\'s answer');
        self::assertSame(self::IDLE_PROBE_SECONDS, $exception->getTimeoutSeconds(), 'the exception must report the bound it hit');
        self::assertStringContainsString('silence', $exception->getMessage(), 'the idle path\'s own marker — this pins which bound fired');
        self::assertNull($exception->getStatusCode(), 'a timeout never produced an HTTP response');
        self::assertNull($exception->getErrorCode());
        self::assertSame([], $records, 'the abort beat the body — no record can have arrived');
        self::assertLessThan(1.0, $elapsed, 'the abort must land inside the server\'s thinking time, not after its answer');
    }

    // --------------------------------------------------------------- the server

    /**
     * Spawn the binary under `serve` on an ephemeral loopback port and wait
     * for /health — skipping the whole suite, loudly, when the binary's
     * serve surface turns out to be broken.
     */
    private static function startServe(): void
    {
        $port = self::findFreePort();
        self::$baseUrl = 'http://127.0.0.1:' . $port;

        $command = sprintf(
            '%s serve --bind 127.0.0.1:%d --no-cache',
            escapeshellarg(self::$binary),
            $port
        );

        // stderr/stdout land in files (not pipes): a chatty server must not
        // block on a full pipe, and a failed start hands its stderr back.
        $stdout = tempnam(sys_get_temp_dir(), 'pdftract-real-serve-out');
        $stderr = tempnam(sys_get_temp_dir(), 'pdftract-real-serve-err');

        self::$serveProcess = proc_open(
            $command,
            [['file', $stdout, 'w'], ['file', $stderr, 'w'], ['file', $stderr, 'w']],
            $pipes
        );

        if (!is_resource(self::$serveProcess)) {
            self::fail("Failed to start `{$command}`");
        }

        $deadline = microtime(true) + self::HEALTH_TIMEOUT_SECONDS;
        $badAnswer = null;

        while (microtime(true) < $deadline) {
            if (!self::serveIsRunning()) {
                self::stopServe();
                self::markTestSkipped(
                    self::BIN_ENV . ' points at ' . self::$binary . ', but the process exited during'
                    . " startup; its stderr:\n" . (string)file_get_contents($stderr)
                );
            }

            $health = self::httpGet(self::$baseUrl . '/health');

            if ($health !== null && $health['status'] === 200) {
                self::$healthBanner = self::decodeJsonObject('/health', $health['body']);

                // A healthy start needs no diagnosis, so the capture files
                // go now — the deadline paths below are the only readers.
                @unlink($stdout);
                @unlink($stderr);

                return;
            }

            // A non-answer is normal during startup; a bad answer is kept
            // for the diagnosis but does not end the poll — a healthy
            // server mid-bind can blip once, and the deadline decides.
            $badAnswer ??= $health;

            usleep(100_000);
        }

        self::stopServe();

        if ($badAnswer !== null) {
            self::markTestSkipped(
                self::BIN_ENV . ' points at ' . self::$binary . ', whose serve surface answers'
                . " GET /health with HTTP {$badAnswer['status']} and body: "
                . substr($badAnswer['body'], 0, 200)
                . "\nThat is the stock binaries' documented defect at the pinned upstream revision:"
                . ' every request 500s inside the audit middleware\'s ConnectInfo extractor'
                . ' (docs/notes/serve-parity-gap.md, Addendum 2026-09-27c). The suite skips rather'
                . ' than fails — a serve-broken binary is the "no feature-complete binary available"'
                . ' case — and runs green against a serve-capable build, such as the probe build'
                . ' that addendum describes.'
            );
        }

        self::markTestSkipped(
            self::BIN_ENV . ' points at ' . self::$binary . ', whose serve surface never answered'
            . ' GET /health within ' . self::HEALTH_TIMEOUT_SECONDS . 's. stderr:'
            . "\n" . (string)file_get_contents($stderr)
            . "\nThis suite skips rather than fails: a binary that cannot serve is the"
            . ' "no feature-complete binary available" case (see the class docblock).'
        );
    }

    /** Terminate and reap the serve process exactly once. */
    private static function stopServe(): void
    {
        if (self::$serveTerminated || !is_resource(self::$serveProcess)) {
            return;
        }

        self::$serveTerminated = true;
        proc_terminate(self::$serveProcess);
        proc_close(self::$serveProcess);
        self::$serveProcess = null;
    }

    private static function serveIsRunning(): bool
    {
        return is_resource(self::$serveProcess)
            && proc_get_status(self::$serveProcess)['running'];
    }

    /** Claim an ephemeral loopback port by binding and releasing it. */
    private static function findFreePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            self::fail("Cannot claim an ephemeral port: {$errstr}");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
    }

    // ------------------------------------------------------------- transports

    /**
     * POST one fixture file as a multipart upload, with no SDK in between
     *
     * @return array{status: int, body: string, contentType: ?string}
     */
    private function postMultipart(string $route, string $fixturePath): array
    {
        return $this->postMultipartBytes($route, (string)file_get_contents($fixturePath));
    }

    /**
     * POST raw bytes as a multipart upload, with no SDK in between
     *
     * The same request shape the client itself builds — one part in the
     * serve API's `file` field, the client's fixed filename, PDF media
     * type — so the only difference from the client's own calls is the
     * absence of the client's response mapping.
     *
     * @return array{status: int, body: string, contentType: ?string}
     */
    private function postMultipartBytes(string $route, string $bytes): array
    {
        $handle = curl_init(self::$baseUrl . $route);
        $contentType = null;

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => new \CURLStringFile($bytes, 'document.pdf', 'application/pdf')],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int)(self::CLIENT_TIMEOUT_SECONDS * 1000),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $headerLine) use (&$contentType): int {
                [$name, $value] = array_pad(explode(':', $headerLine, 2), 2, '');

                if (strtolower(trim($name)) === 'content-type') {
                    $contentType = trim($value);
                }

                return strlen($headerLine);
            },
        ]);

        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        curl_close($handle);

        self::assertSame(0, $errno, "POST {$route} failed at the transport level: {$error}");
        self::assertIsString($body);

        return ['status' => $status, 'body' => $body, 'contentType' => $contentType];
    }

    /**
     * GET a health URL, answering null when the server is not up yet
     *
     * @return array{status: int, body: string}|null
     */
    private static function httpGet(string $url): ?array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 1000,
        ]);

        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return $status !== 0 && is_string($body) ? ['status' => $status, 'body' => $body] : null;
    }

    /**
     * GET a route on the live server, with no SDK in between
     *
     * The wire probe for the two GET routes — the root banner on `/`, the
     * file-path guard on `GET /extract`. The client's surface is
     * POST-only, so those routes have no client leg to assert: the
     * server's own answer is the whole contract, returned in the same
     * shape the POST probes return.
     *
     * @return array{status: int, body: string, contentType: ?string}
     */
    private function getWire(string $route): array
    {
        $handle = curl_init(self::$baseUrl . $route);
        $contentType = null;

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int)(self::CLIENT_TIMEOUT_SECONDS * 1000),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $headerLine) use (&$contentType): int {
                [$name, $value] = array_pad(explode(':', $headerLine, 2), 2, '');

                if (strtolower(trim($name)) === 'content-type') {
                    $contentType = trim($value);
                }

                return strlen($headerLine);
            },
        ]);

        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        curl_close($handle);

        self::assertSame(0, $errno, "GET {$route} failed at the transport level: {$error}");
        self::assertIsString($body);

        return ['status' => $status, 'body' => $body, 'contentType' => $contentType];
    }

    // -------------------------------------------------------------- assertions

    /**
     * Assert a non-2xx wire answer maps to the documented exception, field
     * for field — the contract the buffered suite's provider pins
     * (test_server_errors_map_to_the_documented_exception), now against the
     * real wire instead of a scripted copy of it
     *
     * @param array{status: int, body: string, contentType: ?string} $wire
     */
    private function assertWireRejectionIsMappedVerbatim(string $route, array $wire, ?PdftractException $exception): void
    {
        $body = self::decodeJsonObject($route, $wire['body']);

        // The wire itself must carry the serve-API shape the fixture
        // harness's error rows pin: a JSON object with string `error` and
        // `message` fields and the buffered routes' JSON content type.
        self::assertSame('application/json', $wire['contentType'], $wire['body']);
        self::assertIsString($body['error'] ?? null, $wire['body']);
        self::assertIsString($body['message'] ?? null, $wire['body']);

        $wireHint = is_string($body['hint'] ?? null) ? $body['hint'] : null;

        self::assertNotNull($exception, "a non-2xx response must raise PdftractException ({$route})");
        self::assertSame(ValidationException::class, get_class($exception), "the document-rejection statuses map to ValidationException ({$route})");
        self::assertSame($wire['status'], $exception->getStatusCode(), "the HTTP status must be preserved ({$route})");
        self::assertSame($wire['status'], $exception->getCode(), "the exception code carries the HTTP status ({$route})");
        self::assertSame($body['error'], $exception->getErrorCode(), "the serve API error code must be preserved ({$route})");
        self::assertSame($wireHint, $exception->getHint(), "the hint must travel unchanged, present or absent ({$route})");

        // serverError() composes the caller's message from the wire's
        // fields: the message verbatim, then " (hint: …)" when a hint
        // travels. Pin the composition, not a substring of it.
        $expectedMessage = $body['message'] . ($wireHint !== null ? " (hint: {$wireHint})" : '');
        self::assertSame($expectedMessage, $exception->getMessage(), "the caller's message must be composed from the wire's fields ({$route})");
    }

    /**
     * Run a client call, capturing its result or the PdftractException it
     * raised, so each test can branch on the wire's verdict without
     * try/catch noise
     *
     * @return array{0: mixed, 1: PdftractException|null}
     */
    private function runClient(callable $call): array
    {
        $result = null;
        $exception = null;

        try {
            $result = $call();
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        return [$result, $exception];
    }

    /**
     * Decode a JSON body, failing the test rather than poisoning the
     * comparison with null
     *
     * @return array<string, mixed>
     */
    private static function decodeJsonObject(string $route, string $body): array
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            self::fail("POST {$route} did not answer a JSON object: " . json_last_error_msg() . ' — body: ' . substr($body, 0, 200));
        }

        return $decoded;
    }
}
