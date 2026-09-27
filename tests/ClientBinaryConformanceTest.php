<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs the CLI-subprocess Client (src/Pdftract/Client.php) against a REAL,
 * locally built pdftract binary and classifies every conformance case's
 * outcome as real output vs CLI parse error.
 *
 * tests/ConformanceTest.php only proves the vendored suite is well-formed;
 * actually executing the cases needs a binary. bf-1s2 established that most
 * of the subprocess Client's methods build argv the real clap CLI rejects
 * outright, and its fix children need a before/after signal per method —
 * that is this harness: it does not assert case expectations (the vendored
 * "expected" blocks stay unenforced until the fixes land); it asserts that
 * each case lands in a KNOWN outcome class and emits the per-method report.
 *
 * Outcome classes:
 *
 * - pass          — the method returned (or its stream drained) without the
 *                   binary rejecting anything; real output came back.
 * - parse_error   — non-zero exit whose stderr carries one of clap's parse
 *                   markers ("unexpected argument", "unrecognized
 *                   subcommand", "required arguments were not provided"):
 *                   the argv the Client built never reached pdftract's logic.
 * - runtime_error — non-zero exit WITHOUT a clap marker: the binary parsed
 *                   the argv and then failed for another reason (a missing
 *                   cargo feature, a receipt read as a path, a corrupt PDF).
 * - timeout       — the Client's own subprocess bound fired.
 *
 * Binaries come from the environment:
 *
 * - PDFTRACT_BIN      — a default-features build (the upstream crate's
 *                       default set is empty — a typical release build).
 *                       hash()'s known-good control (bf-1s2) runs against
 *                       this one. Unset: every test skips with a message
 *                       pointing at the build script.
 * - PDFTRACT_BIN_FULL — a feature-complete build (at least "grep"; the
 *                       build script also asks for "profiles" when upstream
 *                       compiles it). The one method whose CLI surface is
 *                       feature-gated (search → the `grep` subcommand) runs
 *                       against THIS one; on a default build it produces a
 *                       bogus parse error that would poison the signal
 *                       (bf-1s2). classify is NOT gated upstream — its
 *                       `classify` subcommand exists in the default build
 *                       (the `profiles` cfg gates extract's --auto/--profile
 *                       flags, not classify) — so it runs against the
 *                       default binary. Unset: just the search cases skip.
 *
 * Build both variants from the upstream pdftract checkout with
 * scripts/build-conformance-binaries.sh — it prints the two env vars.
 *
 * Every case executes in its own PHP subprocess
 * (tests/Support/conformance-runner.php) which loads src/Pdftract/Client.php
 * directly: that file's class names collide with the canonical HTTP client's
 * (both are Jedarden\Pdftract\...), so it cannot be autoloaded inside the
 * PHPUnit process where the canonical client is already defined.
 *
 * The JSON report this harness writes (per-case outcome, per-method counts)
 * deliberately does not follow tests/sdk-conformance/report-schema.json —
 * that schema is for value-level conformance results ("expected" blocks),
 * which a later phase of this work will emit once the methods actually run.
 *
 * Hash semantic pins (pdfphp-fb9eb723): the vendored suite's two hash cases
 * and their `expected` blocks stay unenforced like every other method's —
 * those blocks were authored against the wrapper's fictional
 * {'hash', 'fast_hash'} contract, which upstream never had (the 2026-09-23
 * addendum in docs/notes/serve-parity-gap.md). What IS asserted here, under
 * the hash control above, is the real binary's own hash behaviour, so the
 * sweep harness pins all nine methods' semantics: the INV-13 fingerprint
 * format, determinism, the structural (non-content) fingerprint character
 * (the 11.pdf/12.pdf collision twins), the single-key return shape, byte
 * equality with the raw subcommand, and the failure domain (a bad input is
 * a runtime error, not an argument parse). The wrapper-contract layer for
 * hash() lives alongside, in tests/ClientHashConformanceTest.php.
 *
 * Negative proof (the sibling beads' mutation protocol): mutating hash()'s
 * argument construction in a copy of the tree — `['hash']` → `['hashs']`,
 * or dropping the source positional entirely — flips all seven of these
 * pins to failure with clap's `unrecognized subcommand` /
 * `required arguments were not provided` markers on stderr, plus the four
 * real-binary wrapper pins in ClientHashConformanceTest.php (11 failures
 * across the group under each mutation). The pins bind the argument
 * construction, not just the happy path.
 */
#[Group('binary-conformance')]
class ClientBinaryConformanceTest extends TestCase
{
    /** Vendored conformance suite, relative to this file. */
    private const SUITE_PATH = __DIR__ . '/sdk-conformance';
    private const CASES_PATH = self::SUITE_PATH . '/cases.json';
    private const FIXTURES_PATH = self::SUITE_PATH . '/fixtures/';

    /**
     * The hash pin fixtures. HASH_FIXTURE is the vendored suite's
     * hash-same-file-same-hash case (the control's fixture); HASH_TWIN_FIXTURE
     * is its collision twin — bytewise distinct (different md5s), yet the
     * same structural fingerprint. See the 2026-09-23 addendum in
     * docs/notes/serve-parity-gap.md and pdfphp-ee8d6dbd.
     */
    private const HASH_FIXTURE = 'scientific_paper/11.pdf';
    private const HASH_TWIN_FIXTURE = 'scientific_paper/12.pdf';

    /**
     * Upstream invariant INV-13 (fingerprint/mod.rs:23 at the pinned revision
     * eeab77e): a fingerprint is exactly `pdftract-v1:`, a colon, and 64
     * lowercase hex characters.
     */
    private const INV13_PATTERN = '/^pdftract-v1:[0-9a-f]{64}$/';

    /** The CLI-subprocess client under test (NOT the canonical HTTP client). */
    private const CLIENT_FILE = __DIR__ . '/../src/Pdftract/Client.php';

    /** One-case-per-process executor for the client above. */
    private const RUNNER_SCRIPT = __DIR__ . '/Support/conformance-runner.php';

    /** Where the report lands when PDFTRACT_CONFORMANCE_REPORT is unset. */
    private const DEFAULT_REPORT_PATH = 'pdftract-php-conformance-report.json';

    /**
     * stderr markers that mean clap rejected the argv before pdftract's own
     * logic ran. Anything non-zero without one of these is a runtime error.
     */
    private const CLAP_PARSE_MARKERS = [
        'unexpected argument',
        'unrecognized subcommand',
        'required arguments were not provided',
    ];

    /**
     * Methods whose CLI surface only exists behind a non-default cargo
     * feature, and the feature each one needs. These cases run against the
     * PDFTRACT_BIN_FULL variant; on a default-features binary the subcommand
     * itself is missing, which would masquerade as an SDK parse error.
     *
     * Only search() is genuinely gated: it shells out to the `grep`
     * subcommand, compiled in only with --features grep. classify() targets
     * the ungated `classify` subcommand (upstream gates extract's
     * --auto/--profile tuning behind `profiles`, not classify), so its cases
     * run against the default binary.
     */
    private const FEATURE_GATED = [
        'search' => 'grep',
    ];

    /** Methods bounded by the quick timeout by default (Client::DEFAULT_QUICK_TIMEOUT_SECONDS). */
    private const QUICK_METHODS = ['get_metadata', 'hash', 'classify', 'verify_receipt'];

    /** Outcomes a conformance case may legitimately be classified as. */
    private const CASE_OUTCOMES = ['pass', 'parse_error', 'runtime_error', 'timeout'];

    /** Extra seconds granted to the runner process beyond the Client's own bound. */
    private const RUNNER_GRACE_SECONDS = 60;

    /** Report row length cap for captured stderr fragments. */
    private const DETAIL_MAX_CHARS = 400;

    /** @var array<int, array<string, mixed>> One row per executed case. */
    private static array $rows = [];

    /** @var array<string, array{path: string, version: string}|null> Configured binaries by variant. */
    private static array $binaries = ['default' => null, 'full' => null];

    public static function setUpBeforeClass(): void
    {
        self::$rows = [];

        foreach (array_keys(self::$binaries) as $variant) {
            $env = $variant === 'full' ? 'PDFTRACT_BIN_FULL' : 'PDFTRACT_BIN';
            $path = getenv($env);

            self::$binaries[$variant] = is_string($path) && trim($path) !== ''
                ? ['path' => $path, 'version' => self::binaryVersion($path)]
                : null;
        }
    }

    // ------------------------------------------------------------- the tests

    /**
     * The known-good control (bf-1s2): hash() is the one method whose argv
     * the real CLI already accepts end-to-end. Asserting it green here pins
     * the harness itself — if this fails, the harness or the binary is
     * broken, not the SDK method under fix.
     */
    public function testHashControlPassesEndToEnd(): void
    {
        $case = $this->controlCase();
        $binary = $this->requireBinary('default');
        $envelope = $this->runCase($binary, $case);

        $this->recordRow($binary, 'default', $case, $envelope);

        $this->assertSame(
            'ok',
            $envelope['status'] ?? null,
            sprintf(
                "hash() must pass against the default-features binary (the bf-1s2 known-good control); got: %s",
                $this->describe($envelope)
            )
        );

        $result = $envelope['result'] ?? null;
        $this->assertIsArray(
            $result,
            "hash() must return the fingerprint line under a 'hash' key: " . $this->describe($envelope)
        );
        $this->assertArrayHasKey('hash', $result);
        $this->assertIsString($result['hash']);
        $this->assertNotSame('', $result['hash'], 'hash() returned an empty fingerprint');
    }

    // ---------------------------------------------------- hash semantic pins

    /**
     * The real binary is held to upstream invariant INV-13 exactly —
     * `^pdftract-v1:[0-9a-f]{64}$` (fingerprint/mod.rs:23 at eeab77e). The
     * wrapper's own validation deliberately accepts the version-prefixed
     * family so a future `pdftract-v2:` line passes without a wrapper
     * release (pdfphp-ee8d6dbd); this pin is where v1 format drift gets
     * noticed in the sweep.
     */
    public function testHashFingerprintMatchesInv13Exactly(): void
    {
        $binary = $this->requireBinary('default');
        $envelope = $this->runHashPin($binary, 'hash-inv13', self::HASH_FIXTURE);

        $this->assertSame('ok', $envelope['status'], $this->describe($envelope));
        $this->assertMatchesRegularExpression(
            self::INV13_PATTERN,
            (string) ($envelope['result']['hash'] ?? ''),
            'the fingerprint must be exactly INV-13: version prefix pdftract-v1, colon, 64 lowercase hex'
        );
    }

    /**
     * The vendored suite's hash-same-file-same-hash expectation, enforced:
     * hashing the same file twice yields the byte-identical fingerprint.
     */
    public function testHashSameFileHashesIdenticallyAcrossRuns(): void
    {
        $binary = $this->requireBinary('default');
        $first = $this->runHashPin($binary, 'hash-determinism-first', self::HASH_FIXTURE);
        $second = $this->runHashPin($binary, 'hash-determinism-repeat', self::HASH_FIXTURE);

        $this->assertSame('ok', $first['status'], $this->describe($first));
        $this->assertSame('ok', $second['status'], $this->describe($second));
        $this->assertSame(
            $first['result']['hash'],
            $second['result']['hash'],
            'the same file must hash identically across runs (the fingerprint hashes structure, not wall-clock or path)'
        );
    }

    /**
     * The structural fingerprint is NOT content-addressing: two bytewise
     * DISTINCT fixtures of this suite share one fingerprint. 11.pdf and
     * 12.pdf differ in length and bytes, yet the binary prints the identical
     * `pdftract-v1:` line for both — so the naive pin (assertNotSame across
     * the twins) fails against the real binary and is deliberately inverted
     * here. A future upstream change to what the fingerprint hashes trips
     * this on both sides (the wrapper-side twin lives in
     * tests/ClientHashConformanceTest.php).
     */
    public function testHashFingerprintIsStructuralNotContentAddressed(): void
    {
        $binary = $this->requireBinary('default');

        $onePath = self::FIXTURES_PATH . self::HASH_FIXTURE;
        $twoPath = self::FIXTURES_PATH . self::HASH_TWIN_FIXTURE;
        $this->assertFileExists($onePath);
        $this->assertFileExists($twoPath);
        if (md5_file($onePath) === md5_file($twoPath)) {
            $this->fail('fixtures diverged: the collision pin needs two DISTINCT files');
        }

        $one = $this->runHashPin($binary, 'hash-twin-11', self::HASH_FIXTURE);
        $two = $this->runHashPin($binary, 'hash-twin-12', self::HASH_TWIN_FIXTURE);

        $this->assertSame('ok', $one['status'], $this->describe($one));
        $this->assertSame('ok', $two['status'], $this->describe($two));
        $this->assertSame(
            $one['result']['hash'],
            $two['result']['hash'],
            'the two bytewise-distinct fixtures share a structural fingerprint;'
            . ' if this ever differs, upstream changed what the fingerprint hashes'
        );
    }

    /**
     * The return shape is the fingerprint and nothing else: exactly one
     * 'hash' key. The vendored cases' `expected` blocks promise 'fast_hash'
     * and 'page_count' alongside — keys of the wrapper's retired fictional
     * contract that upstream never printed (serve-parity-gap.md, 2026-09-23
     * addendum side-finding), which is why those blocks stay unenforced and
     * this pin holds the opposite shape.
     */
    public function testHashReturnsTheBareFingerprintUnderASingleKey(): void
    {
        $binary = $this->requireBinary('default');
        $envelope = $this->runHashPin($binary, 'hash-single-key', self::HASH_FIXTURE);

        $this->assertSame('ok', $envelope['status'], $this->describe($envelope));
        $this->assertMatchesRegularExpression(self::INV13_PATTERN, (string) ($envelope['result']['hash'] ?? ''));
        $this->assertSame(
            ['hash' => $envelope['result']['hash']],
            $envelope['result'],
            "hash() returns the fingerprint under a single 'hash' key — no 'fast_hash', no 'page_count'"
        );
    }

    /**
     * The wrapper adds nothing: hash()'s value equals what the binary prints
     * when invoked by hand, byte for byte.
     */
    public function testHashValueEqualsTheRawBinaryOutputByteForByte(): void
    {
        $binary = $this->requireBinary('default');
        $fixturePath = self::FIXTURES_PATH . self::HASH_FIXTURE;

        exec(
            escapeshellarg($binary) . ' hash ' . escapeshellarg($fixturePath),
            $rawLines,
            $exitCode
        );
        $this->assertSame(0, $exitCode, 'the real binary failed to hash the fixture outright');

        $envelope = $this->runHashPin($binary, 'hash-byte-equality', self::HASH_FIXTURE);

        $this->assertSame('ok', $envelope['status'], $this->describe($envelope));
        $this->assertSame(trim(implode("\n", $rawLines)), $envelope['result']['hash']);
    }

    /**
     * The failure domain is the INPUT, not the argument construction: a
     * missing file fails inside the binary — exit code 2, the binary's own
     * fingerprint error on stderr, no clap parse marker — which is exactly
     * what separates hash's argv (accepted end-to-end, the bf-1s2
     * known-good control) from the seven parse_error rows the fix beads are
     * flipping.
     */
    public function testHashFailureIsRuntimeNotArgumentParse(): void
    {
        $binary = $this->requireBinary('default');
        $envelope = $this->runHashPin($binary, 'hash-missing-input', 'scientific_paper/no-such-fixture.pdf');

        $this->assertSame(
            'runtime_error',
            $envelope['outcome'] ?? null,
            'a missing input must fail inside the binary, not at argument parsing: ' . $this->describe($envelope)
        );

        $exception = $envelope['exception'] ?? [];
        $this->assertSame(2, $exception['exit_code'] ?? null, 'the binary exits 2 on an unopenable input');
        $this->assertStringContainsString(
            'Failed to compute fingerprint from file',
            (string) ($exception['message'] ?? ''),
            'the child\'s own error surfaces — the Client does not swallow or rewrite it'
        );
    }

    /**
     * Run one hash semantic pin through the harness's own runner and record
     * it in the report like any classified case.
     *
     * @param string $binary Binary path
     * @param string $id Report row id for the pin
     * @param string $fixture Suite-relative fixture path the pin hashes
     * @return array{status: string, outcome?: string, result?: mixed, exception?: array}
     */
    private function runHashPin(string $binary, string $id, string $fixture): array
    {
        $case = [
            'id' => $id,
            'method' => 'hash',
            'fixture' => $fixture,
            'options' => ['timeout' => 30],
        ];

        $envelope = $this->runCase($binary, $case);
        $this->recordRow($binary, 'default', $case, $envelope);

        return $envelope;
    }

    /**
     * Every vendored case, executed through the Client against a real
     * binary and classified. Deliberately passes whatever the outcome is —
     * the pass/parse_error split IS the result (bf-1s2's fix children flip
     * these rows from parse_error to pass as they fix each method). It only
     * fails when the harness cannot classify what happened.
     */
    #[DataProvider('caseProvider')]
    public function testCaseOutcomeIsClassifiable(array $case): void
    {
        $fixture = $case['fixture'];

        if (str_starts_with($fixture, 'http://') || str_starts_with($fixture, 'https://')) {
            $this->markTestSkipped("Remote fixture (needs network / live transport): {$fixture}");
        }

        $variant = self::variantFor((string) $case['method']);
        $binary = $this->requireBinary($variant);

        $path = self::FIXTURES_PATH . $fixture;
        $this->assertFileExists($path, "Vendored fixture missing for case {$case['id']}: {$fixture}");
        $this->assertFileIsReadable($path);

        $envelope = $this->runCase($binary, $case);
        $this->recordRow($binary, $variant, $case, $envelope);

        if (($envelope['status'] ?? null) === 'harness_error') {
            $this->fail("Harness could not run case {$case['id']}: " . $this->describe($envelope));
        }

        $this->assertContains(
            $envelope['outcome'],
            self::CASE_OUTCOMES,
            "Case {$case['id']} produced an outcome the harness does not classify"
        );
    }

    // ------------------------------------------------------------- provider

    /**
     * Yields each conformance case keyed by its id, so a failing case is
     * reported by name.
     *
     * @return iterable<string, array{0: array}>
     */
    public static function caseProvider(): iterable
    {
        foreach (self::loadSuite()['cases'] as $case) {
            if (isset($case['skip_reason'])) {
                continue;
            }
            yield $case['id'] => [$case];
        }
    }

    // ---------------------------------------------------------- environment

    /**
     * The binary a case runs against, skipping with a clear message when the
     * needed variant was not configured.
     */
    private function requireBinary(string $variant): string
    {
        $env = $variant === 'full' ? 'PDFTRACT_BIN_FULL' : 'PDFTRACT_BIN';
        $configured = self::$binaries[$variant] ?? null;

        if ($configured === null) {
            $featureNote = $variant === 'full'
                ? ' (built with at least --features grep — needed by search(), whose CLI surface is feature-gated)'
                : '';

            $this->markTestSkipped(
                "{$env} is not set — the binary conformance harness runs the CLI-subprocess"
                . " Client against a real pdftract binary{$featureNote}. Build both variants"
                . ' from the upstream pdftract checkout with scripts/build-conformance-binaries.sh,'
                . ' which prints both env vars.'
            );
        }

        $path = $configured['path'];

        if (!is_file($path) || !is_executable($path)) {
            $this->fail("{$env} is set but not an executable file: {$path}");
        }

        return $path;
    }

    /** Which binary variant a method's cases run against. */
    private static function variantFor(string $method): string
    {
        return array_key_exists($method, self::FEATURE_GATED) ? 'full' : 'default';
    }

    /** First line of the binary's --version output, for the report. */
    private static function binaryVersion(string $path): string
    {
        $line = exec(sprintf('%s --version 2>/dev/null', escapeshellarg($path)), $output, $exitCode);

        return $exitCode === 0 && is_string($line) && trim($line) !== '' ? trim($line) : 'unknown';
    }

    // ------------------------------------------------------------ execution

    /**
     * Run one case through the client-in-a-subprocess and attach the
     * classification to the envelope.
     *
     * @return array{status: string, outcome?: string, result?: mixed, exception?: array, detail?: string}
     */
    private function runCase(string $binary, array $case): array
    {
        $options = $case['options'] ?? [];
        $payload = [
            'method' => (string) $case['method'],
            'source' => self::FIXTURES_PATH . (string) $case['fixture'],
            'options' => self::clientOptions(is_array($options) ? $options : []),
        ];

        // Positionals that are not options array members: search()'s pattern
        // and verifyReceipt()'s receipt. The receipt rides as its CONTENT —
        // the Client's contract is the receipt string itself ("Receipt
        // string to verify"), not a path to one, which is exactly the
        // mismatch bf-1s2 documents against the upstream CLI.
        if (isset($options['pattern'])) {
            $payload['pattern'] = (string) $options['pattern'];
        }
        if (isset($options['receipt'])) {
            $receiptPath = self::FIXTURES_PATH . (string) $options['receipt'];
            $payload['receipt'] = is_readable($receiptPath)
                ? (string) file_get_contents($receiptPath)
                : (string) $options['receipt'];
        }

        $command = sprintf(
            'timeout %d %s %s %s %s %s 2>/dev/null',
            $this->wallClockSeconds($case),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::RUNNER_SCRIPT),
            escapeshellarg(self::CLIENT_FILE),
            escapeshellarg($binary),
            escapeshellarg((string) json_encode($payload, JSON_UNESCAPED_SLASHES))
        );

        exec($command, $outputLines, $exitCode);
        $stdout = implode("\n", $outputLines);
        $envelope = json_decode($stdout, true);

        if ($exitCode === 124) {
            return ['status' => 'harness_error', 'detail' => 'runner process exceeded its wall-clock bound'];
        }

        if (!is_array($envelope) || !isset($envelope['status'])) {
            return [
                'status' => 'harness_error',
                'detail' => "runner exited {$exitCode} without a usable envelope; stdout: "
                    . $this->truncate($stdout),
            ];
        }

        if ($envelope['status'] === 'exception') {
            $envelope['outcome'] = $this->classifyException($envelope['exception'] ?? []);
        } elseif ($envelope['status'] === 'ok') {
            $envelope['outcome'] = 'pass';
        }

        return $envelope;
    }

    /**
     * Classify a Client exception as clap parse error, timeout, or
     * other runtime error.
     *
     * @param array $exception The envelope's exception block
     */
    private function classifyException(array $exception): string
    {
        $message = (string) ($exception['message'] ?? '');

        foreach (self::CLAP_PARSE_MARKERS as $marker) {
            if (stripos($message, $marker) !== false) {
                return 'parse_error';
            }
        }

        if (isset($exception['timeout_seconds'])) {
            return 'timeout';
        }

        return 'runtime_error';
    }

    /**
     * Wall-clock bound for the runner process: the Client's own timeout for
     * this case plus grace for interpreter startup, so the runner can never
     * outlive the subprocess bound the Client enforces by much.
     *
     * @param array $case The conformance case
     */
    private function wallClockSeconds(array $case): int
    {
        $options = $case['options'] ?? [];
        $caseTimeout = is_array($options) ? ($options['timeout'] ?? null) : null;
        $default = in_array($case['method'], self::QUICK_METHODS, true) ? 15.0 : 300.0;
        $seconds = is_numeric($caseTimeout) ? (float) $caseTimeout : $default;

        return (int) ceil($seconds) + self::RUNNER_GRACE_SECONDS;
    }

    /**
     * Translate the vendored suite's snake_case option keys into the
     * camelCase keys the Client documents, dropping the two keys that name
     * positionals rather than flags.
     *
     * @param array $options Raw case options
     * @return array Options as the Client's public API expects them
     */
    private static function clientOptions(array $options): array
    {
        $client = [];

        foreach ($options as $key => $value) {
            if (in_array($key, ['pattern', 'receipt'], true)) {
                continue;
            }

            $client[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $key))))] = $value;
        }

        return $client;
    }

    // -------------------------------------------------------------- report

    private function recordRow(string $binary, string $variant, array $case, array $envelope): void
    {
        $exception = is_array($envelope['exception'] ?? null) ? $envelope['exception'] : [];

        self::$rows[] = [
            'id' => (string) $case['id'],
            'method' => (string) $case['method'],
            'fixture' => (string) $case['fixture'],
            'binary_variant' => $variant,
            'binary_path' => $binary,
            'outcome' => $envelope['outcome'] ?? ($envelope['status'] === 'ok' ? 'pass' : 'harness_error'),
            'exception_class' => isset($exception['class']) ? (string) $exception['class'] : null,
            'detail' => isset($exception['message'])
                ? $this->truncate((string) $exception['message'])
                : ($envelope['detail'] ?? null),
        ];
    }

    /**
     * Write the JSON report and print the per-method summary to STDERR so
     * the pass/parse_error signal survives any PHPUnit verbosity setting.
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$rows === []) {
            return; // Nothing ran (binaries unset — every test skipped).
        }

        $byMethod = [];
        foreach (self::$rows as $row) {
            $method = $row['method'];
            $byMethod[$method] ??= array_fill_keys([...self::CASE_OUTCOMES, 'harness_error'], 0);
            $byMethod[$method][$row['outcome']] = ($byMethod[$method][$row['outcome']] ?? 0) + 1;
        }

        $report = [
            'harness' => 'ClientBinaryConformanceTest (CLI-subprocess Client against a real pdftract binary)',
            'client_file' => self::CLIENT_FILE,
            'binaries' => array_map(
                static fn (?array $b) => $b === null ? null : ['path' => $b['path'], 'version' => $b['version']],
                self::$binaries
            ),
            'timestamp' => date(DATE_ATOM),
            'cases' => self::$rows,
            'per_method' => $byMethod,
        ];

        $reportPath = getenv('PDFTRACT_CONFORMANCE_REPORT');
        $reportPath = is_string($reportPath) && trim($reportPath) !== ''
            ? $reportPath
            : sys_get_temp_dir() . '/' . self::DEFAULT_REPORT_PATH;

        if (@file_put_contents($reportPath, (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            fwrite(STDERR, "binary-conformance: could not write report to {$reportPath}\n");
        }

        fwrite(STDERR, self::renderSummary($reportPath, $byMethod));
    }

    /** Human-readable per-method pass/parse-error table, for STDERR. */
    private static function renderSummary(string $reportPath, array $byMethod): string
    {
        $outcomes = [...self::CASE_OUTCOMES, 'harness_error'];
        $header = sprintf('  %-18s', 'method') . implode('', array_map(
            static fn (string $o) => sprintf('%14s', $o),
            $outcomes
        )) . sprintf('%7s', 'total');
        $lines = ["binary-conformance summary (report: {$reportPath})", $header];

        foreach ($byMethod as $method => $counts) {
            $lines[] = sprintf('  %-18s', $method) . implode('', array_map(
                static fn (string $o) => sprintf('%14d', $counts[$o] ?? 0),
                $outcomes
            )) . sprintf('%7d', array_sum($counts));
        }

        return implode("\n", $lines) . "\n";
    }

    // ------------------------------------------------------------ utilities

    /** The hash control case from the vendored suite, by id. */
    private function controlCase(): array
    {
        foreach (self::loadSuite()['cases'] as $case) {
            if ($case['id'] === 'hash-same-file-same-hash') {
                return $case;
            }
        }

        $this->fail('Vendored suite no longer carries the hash-same-file-same-hash control case');
    }

    /**
     * Decode the vendored cases.json, failing with a clear message if the
     * vendored suite is missing or malformed.
     */
    private static function loadSuite(): array
    {
        $json = @file_get_contents(self::CASES_PATH);
        if ($json === false) {
            throw new \RuntimeException(
                'Vendored conformance suite not found at ' . self::CASES_PATH
                . '. See tests/sdk-conformance/README.md.'
            );
        }
        $suite = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($suite) || !isset($suite['cases'])) {
            throw new \RuntimeException(
                'Failed to parse conformance cases JSON: ' . json_last_error_msg()
            );
        }
        return $suite;
    }

    private function describe(array $envelope): string
    {
        $exception = is_array($envelope['exception'] ?? null) ? $envelope['exception'] : [];

        return isset($exception['message'])
            ? sprintf('%s: %s', $exception['class'] ?? 'unknown', $this->truncate((string) $exception['message']))
            : $this->truncate((string) json_encode($envelope));
    }

    private function truncate(string $text): string
    {
        $text = trim($text);

        return strlen($text) > self::DETAIL_MAX_CHARS
            ? substr($text, 0, self::DETAIL_MAX_CHARS) . '…'
            : $text;
    }
}
