<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use Jedarden\Pdftract\Client;
use Jedarden\Pdftract\PdftractException;
use Jedarden\Pdftract\Source;
use Jedarden\Pdftract\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Route output parity for the three COVERED serve routes, pinned against a
 * REAL `pdftract --serve`: POST /extract JSON, POST /extract/text plain
 * text, and POST /extract/stream NDJSON versus their CLI equivalents
 * (`pdftract extract --json -` / `--text -` / `--ndjson`) on the same
 * vendored fixtures, with the canonical HTTP client ({@see Client}) as the
 * third party in every comparison.
 *
 * Three layers are asserted per fixture and route, so a break localises:
 *
 * 1. serve ↔ CLI    — a raw multipart POST (curl, no SDK) must agree with
 *                     the CLI subprocess on the verdict (both fail or both
 *                     succeed) and, on failure, on the error text: the
 *                     buffered body's `message` field for /extract and
 *                     /extract/text, the in-band NDJSON record's `error`
 *                     field for /extract/stream. On success the surfaces
 *                     agree on CONTENT, not bytes — each route is served by
 *                     two writers that genuinely diverge (the --json
 *                     serializers differ in layout, the --text writers and
 *                     the NDJSON schemas differ outright), so each writer
 *                     is pinned against the extraction's canonical JSON
 *                     value and the divergence itself is the pin
 *                     (Addendum 2026-09-27e). This is the empirical half of
 *                     the parity matrix's COVERED rows (docs/notes/
 *                     serve-parity-gap.md).
 * 2. SDK ↔ serve    — the exception the client raises must carry the wire's
 *                     fields unchanged: class from the status (422 →
 *                     ValidationException), error code and message verbatim;
 *                     in-band stream records must surface on the base class.
 * 3. SDK ↔ CLI      — in the failure domain the client's message equals the
 *                     CLI's root cause, so an SDK caller sees what a CLI
 *                     caller would have read off the terminal; on success
 *                     both surfaces are pinned to the one canonical
 *                     document, which is the closest parity that exists.
 *
 * The binary comes from the environment:
 *
 * - PDFTRACT_SERVE_BIN — one pdftract binary, used for BOTH roles (the serve
 *   process it spawns and the CLI subprocess), so the two surfaces compared
 *   are the same code. Unset: every test skips with a message.
 *   scripts/build-serve-bin.sh builds and smoke-gates a serve-capable one:
 *   upstream resolved the ConnectInfo serve defect after the conformance
 *   revision eeab77e — both axum::serve call sites now serve through
 *   into_make_service_with_connect_info — and extraction succeeds, so the
 *   success branches below run (Addendum 2026-09-27e). Those branches were
 *   authored at eeab77e as forward pins asserting byte-level serve↔CLI
 *   equality, at a revision where extraction failed for every document and
 *   only the failure branches could execute; when a binary that could
 *   actually extract finally ran them, the prediction was falsified — no
 *   upstream route shares a success-domain byte format with its CLI
 *   equivalent — and the pins were re-authored to the measured contract
 *   rather than kept as a prediction.
 *
 * The serve lifecycle is owned here: one instance per process, bound to an
 * ephemeral 127.0.0.1 port, polled healthy before any assertion, terminated
 * and reaped in tearDownAfterClass AND in a shutdown function, so a failing
 * run cannot orphan it. Nothing here reaches beyond loopback.
 */
#[Group('serve-parity')]
final class ClientServeParityTest extends TestCase
{
    /** Environment variable carrying the pdftract binary under test. */
    private const BIN_ENV = 'PDFTRACT_SERVE_BIN';

    /**
     * Vendored fixtures, relative to tests/sdk-conformance/fixtures/ — the
     * same twelve the Addendum 2026-09-27 probe drove through the raw CLI
     * and raw multipart POSTs, so the pin's matrix is the probe's matrix.
     */
    private const FIXTURES = [
        'invoice/01.pdf',
        'contract/01.pdf',
        'misc/01.pdf',
        'scientific_paper/01.pdf',
        'code/code.pdf',
        'fillable-form/form.pdf',
        'mixed/mixed.pdf',
        'vertical/vertical.pdf',
        'xmp/xmp-metadata.pdf',
        'large/50pages.pdf',
        'encrypted/encrypted.pdf',
        'broken/corrupt.pdf',
    ];

    private const FIXTURES_PATH = __DIR__ . '/sdk-conformance/fixtures/';

    /** Seconds granted to each CLI subprocess (bounds pathology; full extractions run well inside it). */
    private const CLI_TIMEOUT_SECONDS = 60;

    /** Seconds to wait for GET /health to answer before failing. */
    private const HEALTH_TIMEOUT_SECONDS = 15;

    /** Wall-clock bound for the client's requests (extraction fails fast; this only bounds pathology). */
    private const CLIENT_TIMEOUT_SECONDS = 30;

    private static string $binary = '';

    private static string $baseUrl = '';

    /** @var resource|null proc_open handle of the serve process */
    private static $serveProcess = null;

    /** @var array{0: resource, 1: resource, 2: resource}|null serve process pipes */
    private static array $servePipes = [];

    private static bool $serveTerminated = false;

    public static function setUpBeforeClass(): void
    {
        $binary = getenv(self::BIN_ENV);

        if (!is_string($binary) || trim($binary) === '') {
            self::markTestSkipped(
                self::BIN_ENV . ' is not set — the serve-parity pin runs the real'
                . ' pdftract binary under both `serve` and `extract` and compares the'
                . ' buffered-route responses with the CLI outputs on the vendored'
                . ' fixtures. Point it at a pdftract binary to run; see'
                . ' docs/notes/serve-parity-gap.md, Addendum 2026-09-27.'
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

    // ------------------------------------------------------------- provider

    /**
     * Fixture × CLI output format, each pairing one vendored document with
     * the buffered route that serves it: `--json` ↔ POST /extract,
     * `--text` ↔ POST /extract/text.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function fixtureProvider(): iterable
    {
        foreach (self::FIXTURES as $fixture) {
            yield "{$fixture} --json" => [$fixture, '--json'];
            yield "{$fixture} --text" => [$fixture, '--text'];
        }
    }

    /**
     * Fixture list for the streaming route, whose CLI equivalent is the
     * boolean `--ndjson` flag (stdout, no PATH argument — unlike the other
     * format flags, which take `-`).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function streamFixtureProvider(): iterable
    {
        foreach (self::FIXTURES as $fixture) {
            yield $fixture => [$fixture];
        }
    }

    // ------------------------------------------------------------- the test

    /**
     * One fixture, one buffered route, three layers of parity.
     */
    #[DataProvider('fixtureProvider')]
    public function testBufferedRouteOutputMatchesTheCliOnTheSameFixture(
        string $fixture,
        string $format
    ): void {
        $path = self::FIXTURES_PATH . $fixture;
        $this->assertFileExists($path, 'Vendored fixture missing: ' . $fixture);

        $flag = $format === '--json' ? '--json' : '--text';
        $route = $format === '--json' ? '/extract' : '/extract/text';

        $cli = $this->runCli([$flag, '-'], $path);

        // Layer 1a — the raw serve response, no SDK in between.
        $serve = $this->postMultipart($route, $path);

        // Layer 2 — the SDK client on the same route, same fixture.
        $client = new Client(self::$baseUrl);
        $sdkException = null;
        $sdkResult = null;

        try {
            $sdkResult = $format === '--json'
                ? $client->extract(Source::file($path), ['timeout' => self::CLIENT_TIMEOUT_SECONDS])
                : $client->extractText(Source::file($path), ['timeout' => self::CLIENT_TIMEOUT_SECONDS]);
        } catch (PdftractException $e) {
            $sdkException = $e;
        }

        if ($cli['exitCode'] !== 0) {
            $rootCause = self::cliRootCause($cli['stderr']);

            $this->assertStringContainsString(
                '"error":',
                $serve['body'],
                "serve must fail when the CLI fails ({$fixture} {$format}); body: " . $serve['body']
            );
            $this->assertSame(
                422,
                $serve['status'],
                "serve must reject an unextractable document with 422 ({$fixture} {$format})"
            );
            $this->assertSame(
                $rootCause,
                self::serveErrorMessage($serve['body']),
                "serve/CLI parity break ({$fixture} {$format}): the body's message must be the CLI's root cause"
            );

            // The error domain (forward pin — no current fixture fails;
            // measured at eeab77e): both buffered routes report
            // EXTRACTION_ERROR regardless of payload format.
            $this->assertSame(
                'EXTRACTION_ERROR',
                self::serveErrorCode($serve['body']),
                "serve error code ({$fixture} {$format})"
            );

            $this->assertNotNull($sdkException, "client must raise when the CLI fails ({$fixture} {$format})");
            $this->assertInstanceOf(
                ValidationException::class,
                $sdkException,
                "a 422 must surface as ValidationException ({$fixture} {$format})"
            );
            $this->assertSame($serve['status'], $sdkException->getStatusCode());
            $this->assertSame(
                self::serveErrorCode($serve['body']),
                $sdkException->getErrorCode(),
                "the server's error code must travel unchanged ({$fixture} {$format})"
            );
            $this->assertSame(
                $rootCause,
                $sdkException->getMessage(),
                "SDK/CLI parity break ({$fixture} {$format}): the caller must see the CLI's root cause"
            );

            return;
        }

        // Success domain — live since the serve-capable build (upstream
        // resolved both the ConnectInfo serve defect and the extraction
        // failure; scripts/build-serve-bin.sh produces the binary,
        // Addendum 2026-09-27e). The two surfaces agree on content, not
        // bytes: the CLI pretty-prints JSON while serve compacts it, and
        // the --text writers differ outright (see the text arm below), so
        // parity is pinned on the extracted value, never on byte layouts.
        $this->assertSame(200, $serve['status'], "serve must succeed when the CLI succeeds ({$fixture} {$format})");
        $this->assertNull($sdkException, "client must not raise when the CLI succeeds ({$fixture} {$format})");
        $this->assertNotNull($sdkResult);

        if ($format === '--json') {
            // The decoded-value pins ARE the --json parity pin: the same
            // document from both serializers — the CLI pretty-prints JSON
            // while serve compacts it, and that byte-layout divergence is
            // the documented one. JSON objects are unordered, so the
            // decoded comparison normalizes key layout away.
            $document = self::normalizeJsonValue(self::decodeJson($serve['body']));
            $this->assertSame(
                $document,
                self::normalizeJsonValue(self::decodeJson($cli['stdout'])),
                "serve/CLI parity break ({$fixture} {$format}): the CLI's JSON decodes differently from the serve body"
            );
            $this->assertSame(
                $document,
                self::normalizeJsonValue($sdkResult),
                "SDK/serve break ({$fixture} {$format}): the client's result must be the serve body's faithful decode"
            );

            return;
        }

        // --text: two writers over the one extraction, neither a byte
        // function of the other, and neither surface's body is JSON — so
        // the canonical document comes from a second POST /extract (the
        // same source the stream test pins against). Serve concatenates
        // every span's text with a newline (serve.rs extract_text_handler);
        // the CLI runs serialize_document_text — blocks joined "\n\n",
        // pages joined "\f", header/footer/watermark blocks excluded,
        // figures empty (pdftract-core/src/text.rs). Each writer is pinned
        // against the canonical document, and the writers' divergence is
        // the pin: for the four fixtures with no spans both bodies are the
        // empty string, and for every other fixture they differ (e.g.
        // "Broken PDF" vs "Broken PDF\n").
        $canonicalResponse = $this->postMultipart('/extract', $path);
        $this->assertSame(
            200,
            $canonicalResponse['status'],
            "POST /extract must supply the canonical document ({$fixture} {$format})"
        );
        $document = self::normalizeJsonValue(self::decodeJson($canonicalResponse['body']));

        $this->assertSame(
            self::serveTextFromDocument($document),
            $serve['body'],
            "serve/CLI parity break ({$fixture} --text): the serve text must be the document's span lines"
        );
        $this->assertSame(
            self::cliTextFromDocument($document),
            $cli['stdout'],
            "serve/CLI parity break ({$fixture} --text): the CLI text must be the document's block serialization"
        );
        $this->assertSame(
            $serve['body'],
            $sdkResult,
            "SDK/serve break ({$fixture} --text): the client must return the wire's body verbatim"
        );
    }

    /**
     * One fixture, the streaming route, three layers of parity.
     *
     * The failure domain is deliberately pinned as a divergence from the
     * buffered shape: the CLI fails with rc=1, empty stdout and the root
     * cause on stderr for EVERY output mode, while the serve stream answers
     * HTTP 200 with a single in-band NDJSON error record carrying only
     * `error` — no 422, no error code, no `message` field. Same root cause,
     * structurally different channel; the SDK maps that record to the base
     * {@see PdftractException} with no status and no error code
     * (decodeRecord), where the buffered routes' 422 maps to
     * {@see ValidationException} carrying both.
     *
     * The success domain is a second, deeper divergence (Addendum
     * 2026-09-27e): the two surfaces do not even share a record schema. The
     * serve stream emits one PAGE record per page — the buffered route's
     * `pages[].{index,spans,blocks,tables}`, each optionally carrying an
     * in-band per-page `error` diagnostic string the buffered pages never
     * have — while the CLI's `--ndjson` emits one BLOCK record per block
     * (`{page, block_index, kind, bbox, spans[{text,font,size,bbox}]}`,
     * main.rs's Format::Ndjson arm). Record counts legitimately differ (a
     * page with no blocks yields one serve record and no CLI record), so
     * each surface is pinned against the buffered route's canonical
     * document rather than against each other, and the SDK layer is pinned
     * to decodeRecord's real semantics: a record carrying `error` aborts
     * the stream on the base exception, byte-identical message, with every
     * record before it already yielded.
     */
    #[DataProvider('streamFixtureProvider')]
    public function testStreamRouteOutputMatchesTheCliOnTheSameFixture(string $fixture): void
    {
        $path = self::FIXTURES_PATH . $fixture;
        $this->assertFileExists($path, 'Vendored fixture missing: ' . $fixture);

        $cli = $this->runCli(['--ndjson'], $path);

        // Layer 1a — the raw serve responses, no SDK in between: the stream
        // body under test, plus the buffered route's canonical document the
        // success-domain writers are pinned against.
        $serve = $this->postMultipart('/extract/stream', $path);
        $buffered = $this->postMultipart('/extract', $path);

        // Layer 2 — the SDK client on the same route, same fixture.
        $client = new Client(self::$baseUrl);
        $records = [];
        $sdkException = null;

        try {
            foreach ($client->extractStream(Source::file($path), ['timeout' => self::CLIENT_TIMEOUT_SECONDS]) as $record) {
                $records[] = $record;
            }
        } catch (PdftractException $e) {
            $sdkException = $e;
        }

        if ($cli['exitCode'] !== 0) {
            $rootCause = self::cliRootCause($cli['stderr']);

            // In-band failure: the stream route answers 200 where the
            // buffered routes answer 422 — the divergence is the pin.
            $this->assertSame(
                200,
                $serve['status'],
                "serve /extract/stream must report failure in-band with 200 ({$fixture}); body: " . $serve['body']
            );

            // The body is exactly one newline-terminated NDJSON record whose
            // `error` is the CLI's root cause — no `message` field, which is
            // the stream shape's second divergence from the buffered body.
            $lines = explode("\n", $serve['body']);
            $this->assertCount(2, $lines, "stream body must be one NDJSON line plus its terminator ({$fixture})");
            $this->assertSame('', $lines[1], "stream body must end with a newline ({$fixture})");
            $record = json_decode($lines[0], true);
            $this->assertIsArray($record, "stream error record must be a JSON object ({$fixture}): {$lines[0]}");
            $this->assertSame(
                $rootCause,
                $record['error'] ?? null,
                "serve/CLI parity break ({$fixture} stream): the record's error must be the CLI's root cause"
            );
            $this->assertArrayNotHasKey(
                'message',
                $record,
                "stream error records carry no `message` field ({$fixture}) — buffered-shape bleed-through"
            );

            $this->assertNotNull($sdkException, "client must raise when the CLI fails ({$fixture} stream)");
            $this->assertNotInstanceOf(
                ValidationException::class,
                $sdkException,
                "an in-band error record must NOT surface as a status-mapped subclass ({$fixture} stream)"
            );
            $this->assertSame(
                $rootCause,
                $sdkException->getMessage(),
                "SDK/CLI parity break ({$fixture} stream): the caller must see the CLI's root cause"
            );
            $this->assertNull(
                $sdkException->getStatusCode(),
                "in-band stream errors carry no HTTP status ({$fixture} stream)"
            );
            $this->assertNull(
                $sdkException->getErrorCode(),
                "in-band stream errors carry no error code ({$fixture} stream)"
            );
            $this->assertSame([], $records, "a failing stream must yield no records before raising ({$fixture})");

            return;
        }

        // Success domain — live since the serve-capable build (Addendum
        // 2026-09-27e). The two surfaces do not share a record schema, so
        // each is pinned against the buffered route's canonical document:
        // serve's page records equal the document's pages array (plus the
        // optional in-band `error` diagnostic per page), the CLI's block
        // records equal the document's blocks flattened through its own
        // Ndjson writer, and the SDK layer follows decodeRecord's real
        // abort semantics on the first error-bearing record.
        $this->assertSame(200, $serve['status'], "serve must succeed when the CLI succeeds ({$fixture} stream)");
        $this->assertStringEndsWith("\n", $serve['body'], "the stream body must be newline-terminated ({$fixture})");
        $this->assertSame(200, $buffered['status'], "POST /extract must succeed on the stream fixture ({$fixture})");

        $document = self::normalizeJsonValue(self::decodeJson($buffered['body']));

        $wireRecords = [];
        foreach (explode("\n", $serve['body']) as $line) {
            if (trim($line) !== '') {
                $wireRecords[] = self::decodeJson($line);
            }
        }

        $cliRecords = [];
        foreach (explode("\n", $cli['stdout']) as $line) {
            if (trim($line) !== '') {
                $cliRecords[] = self::decodeJson($line);
            }
        }

        // serve side: one page record per page. An in-band `error` on a
        // page record is the stream's per-page diagnostic channel — the
        // buffered pages never carry it — so it is stripped (after pinning
        // its type) before the record must equal the buffered page.
        $strippedWireRecords = [];
        $firstErrorIndex = null;

        foreach ($wireRecords as $index => $record) {
            if (array_key_exists('error', $record)) {
                $this->assertIsString(
                    $record['error'],
                    "a stream page record's in-band error must be a string ({$fixture})"
                );

                if ($firstErrorIndex === null) {
                    $firstErrorIndex = $index;
                }

                unset($record['error']);
            }

            $strippedWireRecords[] = $record;
        }

        $this->assertSame(
            $document['pages'],
            self::normalizeJsonValue($strippedWireRecords),
            "serve/CLI parity break ({$fixture} stream): the streamed page records must be the buffered pages plus their in-band error diagnostics"
        );

        // CLI side: one block record per block, shaped by the CLI's own
        // Format::Ndjson writer over the same document.
        $this->assertSame(
            self::normalizeJsonValue(self::cliNdjsonRecordsFromDocument($document)),
            self::normalizeJsonValue($cliRecords),
            "serve/CLI parity break ({$fixture} stream): the CLI's block records must be the document's blocks"
        );

        // SDK ↔ serve: every record before the first error-bearing one must
        // have been yielded decoded; the error-bearing record itself must
        // abort the stream on the base class with the error verbatim and no
        // status (nothing failed at the HTTP layer). With no error-bearing
        // record the whole stream must arrive and the client must not raise.
        $records = self::normalizeJsonValue($records);

        if ($firstErrorIndex !== null) {
            $this->assertNotNull($sdkException, "an in-band error record must terminate the stream with an exception ({$fixture})");
            $this->assertSame(PdftractException::class, get_class($sdkException), "an in-band error record must NOT surface as a status-mapped subclass ({$fixture})");
            $this->assertSame($wireRecords[$firstErrorIndex]['error'], $sdkException->getMessage(), "the record's error must surface byte-identical ({$fixture})");
            $this->assertNull($sdkException->getStatusCode(), "an in-band stream error carries no HTTP status ({$fixture})");
            $this->assertNull($sdkException->getErrorCode(), "an in-band stream error carries no error code ({$fixture})");
            $this->assertSame(
                self::normalizeJsonValue(array_slice($wireRecords, 0, $firstErrorIndex)),
                $records,
                "the client must yield exactly the records before the error record, decoded ({$fixture})"
            );

            return;
        }

        $this->assertNull($sdkException, "no record carried an error, so the client must not raise ({$fixture})");
        $this->assertSame(
            self::normalizeJsonValue($wireRecords),
            $records,
            "SDK/serve break ({$fixture} stream): the client must yield the wire's records decoded, in order"
        );
    }

    // ---------------------------------------------------------- serve setup

    /**
     * Spawn the binary under `serve` on an ephemeral loopback port and wait
     * for /health.
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
        $stdout = tempnam(sys_get_temp_dir(), 'pdftract-serve-out');
        $stderr = tempnam(sys_get_temp_dir(), 'pdftract-serve-err');

        self::$serveProcess = proc_open(
            $command,
            [['file', $stdout, 'w'], ['file', $stderr, 'w'], ['file', $stderr, 'w']],
            $pipes
        );

        if (!is_resource(self::$serveProcess)) {
            self::fail("Failed to start `{$command}`");
        }

        self::$servePipes = $pipes;

        $deadline = microtime(true) + self::HEALTH_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            if (!self::serveIsRunning()) {
                self::fail(
                    "serve exited during startup; stderr:\n" . (string)file_get_contents($stderr)
                );
            }

            $health = self::httpGet(self::$baseUrl . '/health');

            if ($health !== null) {
                return;
            }

            usleep(100_000);
        }

        self::fail(
            "serve did not become healthy within " . self::HEALTH_TIMEOUT_SECONDS
            . "s; stderr:\n" . (string)file_get_contents($stderr)
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

    // ------------------------------------------------------------ transports

    /**
     * Run the binary's extract subcommand exactly as a terminal user would.
     *
     * Both output streams land in files, not exec()'s line array: the
     * comparison against the serve body is byte-level, and an implode() would
     * silently rewrite the trailing newline.
     *
     * @param array<int, string> $trailingArgs Output format flag and stdout target
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runCli(array $trailingArgs, string $fixturePath): array
    {
        $stdout = tempnam(sys_get_temp_dir(), 'pdftract-cli-out');
        $stderr = tempnam(sys_get_temp_dir(), 'pdftract-cli-err');

        $command = sprintf(
            'timeout %d %s extract %s %s >%s 2>%s',
            self::CLI_TIMEOUT_SECONDS,
            escapeshellarg(self::$binary),
            escapeshellarg($fixturePath),
            implode(' ', array_map('escapeshellarg', $trailingArgs)),
            escapeshellarg($stdout),
            escapeshellarg($stderr)
        );

        exec($command, $outputLines, $exitCode);

        return [
            'exitCode' => $exitCode,
            'stdout' => (string)file_get_contents($stdout),
            'stderr' => (string)file_get_contents($stderr),
        ];
    }

    /**
     * POST one fixture as a multipart upload, with no SDK in between.
     *
     * @return array{status: int, body: string}
     */
    private function postMultipart(string $route, string $fixturePath): array
    {
        $handle = curl_init(self::$baseUrl . $route);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => new \CURLStringFile(
                (string)file_get_contents($fixturePath),
                'document.pdf',
                'application/pdf'
            )],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => self::CLIENT_TIMEOUT_SECONDS * 1000,
        ]);

        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        curl_close($handle);

        $this->assertSame(0, $errno, "POST {$route} failed at the transport level: {$error}");
        $this->assertIsString($body);

        return ['status' => $status, 'body' => $body];
    }

    /**
     * GET a health URL, answering null when the server is not up yet.
     */
    private static function httpGet(string $url): ?string
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 1000,
        ]);

        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return $status === 200 && is_string($body) ? $body : null;
    }

    // ------------------------------------------------------------ utilities

    /**
     * The root-cause line a failing CLI run leaves on stderr
     *
     * anyhow prints error chains top-down, so the root cause is the last
     * non-empty line, indented under `Caused by:` (or the whole `Error: …`
     * line when there is no chain). The prefix is stripped only in the
     * no-chain case — with a chain, the top-level context line ("Failed to
     * extract PDF") is not the cause the server reports.
     *
     * @param string $stderr The subprocess's stderr
     */
    private static function cliRootCause(string $stderr): string
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $stderr)),
            static fn (string $line) => $line !== ''
        ));

        $last = $lines === [] ? '' : (string)end($lines);

        if (in_array('Caused by:', $lines, true)) {
            return $last;
        }

        return preg_replace('/^Error:\s*/', '', $last) ?? $last;
    }

    /**
     * The `message` field of a serve error body, decoded defensively.
     */
    private static function serveErrorMessage(string $body): string
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) && is_string($decoded['message'] ?? null)
            ? $decoded['message']
            : '';
    }

    /**
     * The `error` field of a serve error body, decoded defensively.
     */
    private static function serveErrorCode(string $body): string
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) && is_string($decoded['error'] ?? null)
            ? $decoded['error']
            : '';
    }

    /**
     * Decode a CLI JSON stdout, failing the test rather than poisoning the
     * comparison with null.
     *
     * @return mixed
     */
    private static function decodeJson(string $json): mixed
    {
        $decoded = json_decode($json, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            self::fail('CLI stdout is not valid JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Deep-normalize a decoded JSON value for comparison: object keys are
     * sorted recursively — JSON objects are unordered and the serializers
     * need not agree on layout — while list order and scalar types stay
     * strict, so the result pins content exactly.
     */
    private static function normalizeJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = array_map(static fn (mixed $item): mixed => self::normalizeJsonValue($item), $value);

        if (!array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    /**
     * POST /extract/text's writer, over the buffered route's decoded
     * document: every span's text followed by a newline, pages flattened in
     * order (serve.rs extract_text_handler at the audited revision).
     *
     * @param array<string, mixed> $document
     */
    private static function serveTextFromDocument(array $document): string
    {
        $text = '';

        foreach ($document['pages'] as $page) {
            foreach ($page['spans'] as $span) {
                $text .= $span['text'] . "\n";
            }
        }

        return $text;
    }

    /**
     * The CLI `--text` writer (pdftract-core/src/text.rs
     * serialize_document_text): per page, the readable blocks' text joined
     * with "\n\n" — header/footer/watermark blocks excluded, figures
     * contributing no text — and pages joined with "\f" (N pages → N-1
     * form feeds, none leading or trailing).
     *
     * @param array<string, mixed> $document
     */
    private static function cliTextFromDocument(array $document): string
    {
        $pageTexts = [];

        foreach ($document['pages'] as $page) {
            $blockTexts = [];

            foreach ($page['blocks'] as $block) {
                if (in_array($block['kind'], ['header', 'footer', 'watermark'], true)) {
                    continue;
                }

                $blockTexts[] = $block['kind'] === 'figure' ? '' : $block['text'];
            }

            $pageTexts[] = implode("\n\n", $blockTexts);
        }

        return implode("\f", $pageTexts);
    }

    /**
     * The CLI `--ndjson` writer (main.rs Format::Ndjson arm): one record
     * per block — `{page, block_index, kind, bbox, spans}` — with the
     * block's span indices resolved against the page's span array into
     * `{text, font, size, bbox}` objects.
     *
     * @param array<string, mixed> $document
     * @return list<array<string, mixed>>
     */
    private static function cliNdjsonRecordsFromDocument(array $document): array
    {
        $records = [];

        foreach ($document['pages'] as $page) {
            foreach ($page['blocks'] as $blockIndex => $block) {
                $spans = [];

                foreach ($block['spans'] ?? [] as $spanIndex) {
                    $span = $page['spans'][$spanIndex];
                    $spans[] = [
                        'text' => $span['text'],
                        'font' => $span['font'],
                        'size' => $span['size'],
                        'bbox' => $span['bbox'],
                    ];
                }

                $records[] = [
                    'page' => $page['index'],
                    'block_index' => $blockIndex,
                    'kind' => $block['kind'],
                    'bbox' => $block['bbox'],
                    'spans' => $spans,
                ];
            }
        }

        return $records;
    }
}
