<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Conformance pin for Client::hash() — the CLI-subprocess wrapper at
 * src/Pdftract/Client.php — against what the real `pdftract hash`
 * subcommand actually does (bead pdfphp-ee8d6dbd).
 *
 * Why hash() has this file: it is the one ADR-1 method the binary-transport
 * repair round had no bead for, and its wrapper contract used to be pure
 * fiction — the retired docblock advertised a decoded {'hash', 'fast_hash'}
 * object with a 'fast' option, while upstream prints a single bare
 * fingerprint line and the subcommand takes no such flag
 * (docs/notes/serve-parity-gap.md, 2026-09-23 addendum side-finding). Every
 * successful hash() threw "Failed to decode JSON output". The upstream
 * facts this file pins, all audited at the pinned revision
 * `pdftract` @ `eeab77e`:
 *
 * - the `hash` subcommand is ungated and ships in the default build —
 *   `Hash { input, password, header }` (cli.rs:215-227), dispatched to
 *   `run_hash` (main.rs:748, 781);
 * - `run_hash` is a plain `println!` of the fingerprint — no --json flag,
 *   no other stdout output (hash.rs:300-327);
 * - the fingerprint's format is upstream's own invariant INV-13:
 *   `^pdftract-v1:[0-9a-f]{64}$` (fingerprint/mod.rs:23 and the
 *   `test_inv13_fingerprint_format` invariant test);
 * - the fingerprint is STRUCTURAL, not content-addressing: it hashes
 *   page-tree/structure-tree/catalog data, and content streams only insofar
 *   as they alter that structure. Empirically, two bytewise-distinct
 *   fixtures of this suite (scientific_paper/11.pdf and 12.pdf — 2534 vs
 *   2553 bytes, different md5s) print the SAME fingerprint, so "different
 *   documents never collide" is NOT pinnable and this file pins the
 *   collision instead (see the fixture test below);
 * - `pdftract --serve` has NO hash route (serve.rs:406-414) and the
 *   fingerprint embedded in `POST /extract` is computed over catalog-only
 *   input (page_count: 0, pages: vec![]; document.rs:1617-1618), so it is
 *   not the value the hash subcommand prints (serve-parity-gap.md
 *   addendum) — which is why this pin holds the wrapper to the CLI
 *   subcommand, not to anything a serve response carries.
 *
 * Note on the vendored suite's hash cases
 * (tests/sdk-conformance/cases.json: hash-same-file-same-hash,
 * hash-content-stability): their `expected` blocks (`fast_hash`,
 * `page_count`, `content_hash_stable`) were authored against the wrapper's
 * fictional contract, and the real binary prints none of those keys — the
 * fixtures are reused here, the expectations are not. What is pinnable from
 * upstream is INV-13, determinism, and the structural (non-content)
 * character above.
 *
 * Two layers, matching how the rest of the binary-conformance work is
 * organised:
 *
 * - Wrapper-contract cases (no binary needed): throwaway shell scripts
 *   stand in for pdftract and emit canned stdout, pinning what hash()
 *   itself does — pass the `hash` subcommand and the source, return the
 *   line verbatim under a single 'hash' key, and reject anything that is
 *   not one fingerprint line (the fiction above included) with
 *   PdftractException. These run in the default suite.
 * - Real-binary cases (group `binary-conformance`): run against the
 *   default-features build via PDFTRACT_BIN and hold today's binary to
 *   INV-13 exactly. Like tests/ClientBinaryConformanceTest.php they skip
 *   with a pointer to scripts/build-conformance-binaries.sh when the env
 *   var is unset, so a plain green suite run needs no binary.
 *
 * Every case drives the wrapper in its own PHP subprocess
 * (tests/Support/hash-conformance-runner.php): src/Pdftract/Client.php
 * declares the same fully-qualified class names as the canonical HTTP
 * client, so it cannot be loaded inside the PHPUnit process.
 */
class ClientHashConformanceTest extends TestCase
{
    /** The CLI-subprocess client under test (NOT the canonical HTTP client). */
    private const CLIENT_FILE = __DIR__ . '/../src/Pdftract/Client.php';

    /** One-case-per-process executor for the client above. */
    private const RUNNER_SCRIPT = __DIR__ . '/Support/hash-conformance-runner.php';

    /** Fixture of the vendored suite's hash-same-file-same-hash case. */
    private const HASH_FIXTURE = __DIR__ . '/sdk-conformance/fixtures/scientific_paper/11.pdf';

    /**
     * Fixture of the vendored suite's hash-content-stability case — and the
     * collision twin of HASH_FIXTURE: bytewise distinct (2534 vs 2553
     * bytes), yet the same structural fingerprint (pinned below).
     */
    private const OTHER_FIXTURE = __DIR__ . '/sdk-conformance/fixtures/scientific_paper/12.pdf';

    /** Wall-clock bound for one runner process (hash is a quick call; this is generous). */
    private const RUNNER_WALL_CLOCK_SECONDS = 60;

    /** Per-call timeout the deadline case hands the Client, in seconds. */
    private const DEADLINE_SECONDS = 1.0;

    /** This test's scratch dir, created on first use and removed in tearDown. */
    private ?string $scratchDir = null;

    // ------------------------------------------------------------- the tests

    /**
     * A successful hash is returned verbatim, as printed, under a single
     * 'hash' key — the wrapper is transport, not transformation. There is
     * no 'fast_hash' key and no 'fast' option: those never existed upstream.
     */
    public function testHashReturnsTheFingerprintLineVerbatim(): void
    {
        $line = self::fingerprintLine(str_repeat('ab', 32));
        $binary = $this->fakePdftract("printf '%s\\n' " . escapeshellarg($line));

        $envelope = $this->runHash($binary, '/tmp/any.pdf');

        $this->assertSame('ok', $envelope['status'], $this->describe($envelope));
        $this->assertSame(['hash' => $line], $envelope['result']);
    }

    /**
     * The wrapper's validation accepts the version-prefixed FAMILY, not
     * just today's v1 — a future upstream `pdftract-v2:` line still passes
     * (the INV-13-exact v1 pin lives in the real-binary group below, where
     * drift gets noticed against the binary rather than the wrapper).
     */
    public function testHashAcceptsTheVersionPrefixedFamily(): void
    {
        $line = 'pdftract-v2:' . str_repeat('ef', 32);
        $binary = $this->fakePdftract("printf '%s\\n' " . escapeshellarg($line));

        $envelope = $this->runHash($binary, '/tmp/any.pdf');

        $this->assertSame('ok', $envelope['status'], $this->describe($envelope));
        $this->assertSame(['hash' => $line], $envelope['result']);
    }

    /**
     * Anything that is not one bare fingerprint line is rejected, even
     * though the child exited 0 — including the fictional {'hash',
     * 'fast_hash'} JSON the retired docblock advertised, whose JSON-decode
     * was exactly how the old implementation failed on every real hash.
     *
     * @dataProvider nonFingerprintOutputProvider
     */
    public function testHashRejectsOutputThatIsNotOneFingerprintLine(string $stdout, string $why): void
    {
        $binary = $this->fakePdftract("printf '%s\\n' " . escapeshellarg($stdout));

        $envelope = $this->runHash($binary, '/tmp/any.pdf');

        $this->assertSame('exception', $envelope['status'], "should reject ({$why}): " . $this->describe($envelope));
        $exception = $envelope['exception'];
        $this->assertSame(\Jedarden\Pdftract\PdftractException::class, $exception['class']);
        $this->assertStringStartsWith('Unexpected output from hash command:', $exception['message']);
        $this->assertSame(-1, $exception['exit_code'], 'shape mismatch is not the child\'s exit code');
    }

    /**
     * The wrapper passes the `hash` subcommand, then the source, then
     * option flags verbatim — and consumes the 'timeout' option itself
     * instead of leaking it as a flag the CLI would reject.
     */
    public function testHashPassesHashSubcommandAndSourceWithoutTimeoutFlag(): void
    {
        $logPath = $this->scratchPath('argv.log');
        $line = self::fingerprintLine(str_repeat('cd', 32));
        $binary = $this->fakePdftract(
            'printf \'%s\\n\' "$@" > ' . escapeshellarg($logPath) . "\nprintf '%s\\n' " . escapeshellarg($line)
        );
        $source = $this->scratchPath('input.pdf');
        touch($source);

        $envelope = $this->runHash($binary, $source, ['password' => 'secret', 'timeout' => 9.0]);

        $this->assertSame('ok', $envelope['status'], $this->describe($envelope));
        $this->assertFileExists($logPath, 'fake binary was never handed the argv');
        $args = array_map('trim', file($logPath) ?: []);
        $this->assertSame(['hash', $source, '--password', 'secret'], $args);
    }

    /**
     * hash() is a buffered call bounded by a total wall-clock deadline (the
     * class docblock's contract): a child that emits nothing past the
     * deadline is terminated and surfaces TimeoutException, exit code 124.
     */
    public function testHashHonoursItsBufferedDeadline(): void
    {
        $binary = $this->fakePdftract('sleep 30');

        $envelope = $this->runHash($binary, '/tmp/any.pdf', ['timeout' => self::DEADLINE_SECONDS]);

        $this->assertSame('exception', $envelope['status'], $this->describe($envelope));
        $exception = $envelope['exception'];
        $this->assertSame(\Jedarden\Pdftract\TimeoutException::class, $exception['class']);
        // Cast, not assertSame on the raw value: the envelope is JSON, and a
        // JSON round-trip collapses 1.0 to int 1. The exception itself is
        // typed float; the contract pinned here is the deadline's value.
        $this->assertSame(self::DEADLINE_SECONDS, (float) ($exception['timeout_seconds'] ?? -1));
        $this->assertSame(124, $exception['exit_code']);
    }

    // ------------------------------------------------- real-binary (INV-13)

    /**
     * Today's binary held to INV-13 exactly — `^pdftract-v1:[0-9a-f]{64}$`.
     * The wrapper's validation deliberately accepts the version-prefixed
     * family (future v2 without a wrapper release); this pin is where a
     * format drift gets noticed first.
     */
    #[Group('binary-conformance')]
    public function testRealBinaryFingerprintMatchesInv13Exactly(): void
    {
        $binary = $this->requireRealBinary();

        $envelope = $this->runHash($binary, self::HASH_FIXTURE);

        $this->assertSame('ok', $envelope['status'], $this->describe($envelope));
        $this->assertMatchesRegularExpression(
            '/^pdftract-v1:[0-9a-f]{64}$/',
            (string) ($envelope['result']['hash'] ?? ''),
            'the fingerprint must be exactly INV-13: version prefix pdftract-v1, colon, 64 lowercase hex'
        );
    }

    /**
     * The vendored suite's hash-same-file-same-hash case: hashing the same
     * file twice yields the same fingerprint.
     */
    #[Group('binary-conformance')]
    public function testRealBinarySameFileHashesIdentically(): void
    {
        $binary = $this->requireRealBinary();

        $first = $this->runHash($binary, self::HASH_FIXTURE);
        $second = $this->runHash($binary, self::HASH_FIXTURE);

        $this->assertSame('ok', $first['status'], $this->describe($first));
        $this->assertSame('ok', $second['status'], $this->describe($second));
        $this->assertSame($first['result']['hash'], $second['result']['hash']);
    }

    /**
     * The structural fingerprint is not content-addressing: two bytewise
     * DISTINCT documents of this suite share one fingerprint. 11.pdf and
     * 12.pdf differ in length and bytes (different md5s), yet the binary
     * prints the identical `pdftract-v1:ab24a95f…a8a8` line for both —
     * verified against the default-features build, and consistent with the
     * static audit: the fingerprint hashes structure (page-tree digests,
     * structure tree, catalog flags — fingerprint/mod.rs header at
     * eeab77e), not content streams. The naive pin — assertNotSame across
     * the two fixtures — FAILS against the real binary and is deliberately
     * inverted here, so a future "hash is a content digest" drift gets
     * noticed on both sides.
     */
    #[Group('binary-conformance')]
    public function testRealBinaryStructuralFingerprintIsNotContentAddressing(): void
    {
        $binary = $this->requireRealBinary();

        if (md5_file(self::HASH_FIXTURE) === md5_file(self::OTHER_FIXTURE)) {
            $this->fail('fixtures diverged: the collision pin needs two DISTINCT files');
        }

        $one = $this->runHash($binary, self::HASH_FIXTURE);
        $two = $this->runHash($binary, self::OTHER_FIXTURE);

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
     * The wrapper adds nothing: hash()'s value equals what the binary
     * prints when invoked by hand, byte for byte.
     */
    #[Group('binary-conformance')]
    public function testRealBinaryClientResultEqualsRawBinaryOutput(): void
    {
        $binary = $this->requireRealBinary();

        exec(
            escapeshellcmd($binary) . ' hash ' . escapeshellarg(self::HASH_FIXTURE),
            $rawLines,
            $exitCode
        );
        $this->assertSame(0, $exitCode, 'the real binary failed to hash the fixture outright');

        $envelope = $this->runHash($binary, self::HASH_FIXTURE);

        $this->assertSame('ok', $envelope['status'], $this->describe($envelope));
        $this->assertSame(trim(implode("\n", $rawLines)), $envelope['result']['hash']);
    }

    // ------------------------------------------------------------- provider

    /**
     * Stdout shapes that must all be rejected: prose, the fictional JSON
     * contract, multi-line output, uppercase hex, wrong digest length,
     * empty output, trailing junk.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function nonFingerprintOutputProvider(): iterable
    {
        $hex = str_repeat('ab', 32);

        yield 'plain prose' => ['hello world', 'prose is not a fingerprint'];

        yield 'the fictional json contract' => [
            '{"hash":"pdftract-v1:' . $hex . '","fast_hash":"' . str_repeat('cd', 32) . '"}',
            'the retired docblock\'s {"hash","fast_hash"} object never existed upstream',
        ];

        yield 'fingerprint plus extra line' => [
            self::fingerprintLine($hex) . "\npdftract-v1:" . str_repeat('ef', 32),
            'more than one line of output is not a bare fingerprint',
        ];

        yield 'uppercase hex' => [
            self::fingerprintLine(strtoupper($hex)),
            'INV-13 allows lowercase hex only',
        ];

        yield 'short digest' => [
            self::fingerprintLine(substr($hex, 0, 62)),
            'the digest must be exactly 64 hex characters',
        ];

        yield 'no output at all' => [
            '',
            'a successful hash prints exactly one line',
        ];

        yield 'trailing junk on the line' => [
            self::fingerprintLine($hex) . ' and then some',
            'nothing may follow the digest',
        ];
    }

    // ---------------------------------------------------------- environment

    /**
     * The default-features binary the real-binary cases run against,
     * skipping with the harness's message when it is not configured.
     */
    private function requireRealBinary(): string
    {
        $path = getenv('PDFTRACT_BIN');

        if (!is_string($path) || trim($path) === '') {
            $this->markTestSkipped(
                'PDFTRACT_BIN is not set — these cases run Client::hash() against a real pdftract'
                . ' binary. Build it from the upstream pdftract checkout with'
                . ' scripts/build-conformance-binaries.sh, which prints the env var.'
            );
        }

        if (!is_file($path) || !is_executable($path)) {
            $this->fail('PDFTRACT_BIN is set but not an executable file: ' . $path);
        }

        return $path;
    }

    // ------------------------------------------------------------ execution

    /**
     * Run hash() once through the wrapper in its own PHP subprocess.
     *
     * @param array<string, mixed> $options
     * @return array{status: string, result?: mixed, exception?: array{class: string, message: string, exit_code: ?int, timeout_seconds: ?float}}
     */
    private function runHash(string $binary, string $source, array $options = []): array
    {
        $command = sprintf(
            'timeout %d %s %s %s %s %s %s 2>/dev/null',
            self::RUNNER_WALL_CLOCK_SECONDS,
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::RUNNER_SCRIPT),
            escapeshellarg(self::CLIENT_FILE),
            escapeshellarg($binary),
            escapeshellarg($source),
            escapeshellarg((string) json_encode($options, JSON_UNESCAPED_SLASHES))
        );

        exec($command, $outputLines, $exitCode);
        $stdout = implode("\n", $outputLines);
        $envelope = json_decode($stdout, true);

        if (!is_array($envelope) || !isset($envelope['status'])) {
            $this->fail(
                "hash conformance runner exited {$exitCode} without a usable envelope; stdout: "
                . substr($stdout, 0, 400)
            );
        }

        return $envelope;
    }

    // ------------------------------------------------------------ utilities

    /**
     * A throwaway `pdftract` stand-in: a shell script with the given body,
     * executable, in the test's scratch dir.
     */
    private function fakePdftract(string $shBody): string
    {
        $path = $this->scratchPath('fake-pdftract');
        if (@file_put_contents($path, "#!/bin/sh\n{$shBody}\n") === false) {
            $this->fail("could not write fake binary: {$path}");
        }
        if (!@chmod($path, 0755)) {
            $this->fail("could not make the fake binary executable: {$path}");
        }

        return $path;
    }

    /** A path inside this test's scratch dir, which is created on first use. */
    private function scratchPath(string $name): string
    {
        if ($this->scratchDir === null) {
            $dir = sys_get_temp_dir() . '/pdftract-hash-conformance-' . uniqid('', true);
            if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                $this->fail("could not create scratch dir: {$dir}");
            }
            $this->scratchDir = $dir;
        }

        return $this->scratchDir . '/' . $name;
    }

    /** A well-formed INV-13 fingerprint line over the given hex. */
    private static function fingerprintLine(string $hex): string
    {
        return 'pdftract-v1:' . $hex;
    }

    /** One line describing a non-ok envelope, for assertion messages. */
    private function describe(array $envelope): string
    {
        $exception = is_array($envelope['exception'] ?? null) ? $envelope['exception'] : [];

        return isset($exception['message'])
            ? sprintf('%s: %s', $exception['class'] ?? 'unknown', (string) $exception['message'])
            : trim((string) json_encode($envelope));
    }

    protected function tearDown(): void
    {
        if ($this->scratchDir !== null) {
            self::removeRecursively($this->scratchDir);
            $this->scratchDir = null;
        }
    }

    private static function removeRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($entries as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeRecursively($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
