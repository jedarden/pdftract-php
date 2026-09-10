<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests\Retired;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SUPERSEDED — retired with the CLI subprocess transport. Never run these
 * cases against the canonical HTTP client.
 *
 * Partition rationale (2026-09-10): these are the subprocess-timeout cases of
 * the old tests/ClientTimeoutTest.php, which proved that the proc_open
 * transport bounded every pdftract child it spawned: wall-clock deadlines,
 * idle bounds on streams, SIGTERM/SIGKILL of timed-out and abandoned
 * children, pipe draining under stderr floods, and the timeout
 * configuration/validation contract.
 *
 * ADR-1 (docs/plan/plan.md) replaced that transport with HTTP against
 * `pdftract --serve`, and PSR-4 now resolves Jedarden\Pdftract\Client to the
 * canonical HTTP client, so this contract no longer describes the shipped
 * code. Per bf-4gq the legacy suite was partitioned rather than deleted and
 * these cases are retired with the transport they tested. The canonical HTTP
 * client re-implements the same bounding guarantees with curl
 * (CURLOPT_TIMEOUT for buffered calls, an idle bound via curl_multi for
 * streams); covering those belongs to the HTTP client's own suite, not here.
 *
 * The partition is inert, by construction:
 *
 * - phpunit.xml excludes the `retired-cli-subprocess` group from the default
 *   suite, so vendor/bin/phpunit never runs it.
 * - setUp() skips every case before any body executes.
 * - No case references the canonical client. The Client, TimeoutException,
 *   PdftractException, and ConfigurationException names appearing in the
 *   bodies are deliberately left unqualified and unresolved: when written
 *   they named the transport classes under src/Pdftract, whose namespace the
 *   PSR-4 root now resolves to the canonical HTTP client's classes instead.
 *   Importing those names here would silently re-point this record at the
 *   wrong class, so the bodies are preserved verbatim as a record and never
 *   execute. Do not add use statements for them.
 *
 * To read this contract as live code, check out a revision from before the
 * ADR-1 HTTP-client migration (git log -- tests/ClientTimeoutTest.php).
 */
#[Group('retired-cli-subprocess')]
class ClientSubprocessTimeoutTest extends TestCase
{
    /** Why every case in this partition refuses to run. */
    private const RETIRED =
        'Retired with the CLI subprocess transport (ADR-1 in docs/plan/plan.md,'
        . ' bf-4gq): preserved as a record of the old contract; not run against'
        . ' the HTTP client.';

    private string $workDir;

    protected function setUp(): void
    {
        // This partition is inert: skip before touching the filesystem. With
        // the group excluded (phpunit.xml) these cases are never enumerated;
        // if someone selects the group explicitly anyway, every case reports
        // a skip and no body — and no client — is ever constructed.
        $this->markTestSkipped(self::RETIRED);

        $dir = sys_get_temp_dir() . '/pdftract-timeout-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0o700) && !is_dir($dir)) {
            $this->fail('Could not create temp dir: ' . $dir);
        }
        $this->workDir = $dir;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);
    }

    /**
     * Writes an executable stand-in for the pdftract binary.
     *
     * @param string $name Script filename
     * @param string $body PHP source (without the opening tag)
     * @return string Path to the executable script
     */
    private function fakeBinary(string $name, string $body): string
    {
        $path = $this->workDir . '/' . $name;
        file_put_contents($path, '#!' . PHP_BINARY . "\n<?php\n" . $body . "\n");
        chmod($path, 0o700);

        return $path;
    }

    /** A stand-in that never finishes within any test's timeout. */
    private function sleepingBinary(): string
    {
        return $this->fakeBinary('sleeper', 'sleep(30);');
    }

    public function testBufferedCallTimesOutAndDoesNotBlockIndefinitely(): void
    {
        $client = new Client($this->sleepingBinary(), null, 0.3);

        $start = microtime(true);
        try {
            $client->extract('/nonexistent.pdf');
            $this->fail('Expected TimeoutException');
        } catch (TimeoutException $e) {
            $elapsed = microtime(true) - $start;
            $this->assertLessThan(5.0, $elapsed, 'Client should give up close to the configured timeout');
            $this->assertSame(0.3, $e->getTimeoutSeconds());
            $this->assertSame(TimeoutException::EXIT_CODE, $e->getExitCode());
            $this->assertInstanceOf(PdftractException::class, $e);
        }
    }

    public function testTimedOutChildIsTerminated(): void
    {
        $marker = $this->workDir . '/finished';
        $binary = $this->fakeBinary('marker', 'sleep(3); file_put_contents(' . var_export($marker, true) . ", 'done');");

        $client = new Client($binary, null, 0.3);

        $this->expectException(TimeoutException::class);
        try {
            $client->extract('/nonexistent.pdf');
        } finally {
            // Well past the child's own 3s sleep: if it had survived the timeout
            // it would have written the marker by now.
            usleep(4_000_000);
            $this->assertFileDoesNotExist($marker, 'Timed-out child should have been killed, not left running');
        }
    }

    public function testFastCommandStillSucceeds(): void
    {
        $binary = $this->fakeBinary('ok', 'echo json_encode(["schema_version" => "1.0", "pages" => []]);');
        $client = new Client($binary, null, 5.0);

        $this->assertSame(['schema_version' => '1.0', 'pages' => []], $client->extract('/some.pdf'));
    }

    public function testPerCallTimeoutOptionOverridesClientDefault(): void
    {
        $client = new Client($this->sleepingBinary(), null, 60.0);

        $start = microtime(true);
        try {
            $client->extract('/some.pdf', ['timeout' => 0.3]);
            $this->fail('Expected TimeoutException');
        } catch (TimeoutException $e) {
            $this->assertLessThan(5.0, microtime(true) - $start);
            $this->assertSame(0.3, $e->getTimeoutSeconds());
        }
    }

    public function testTimeoutOptionIsNotForwardedToTheCli(): void
    {
        $binary = $this->fakeBinary('echoargs', 'echo json_encode(["args" => array_slice($argv, 1)]);');
        $client = new Client($binary, null, 5.0);

        $result = $client->extract('/some.pdf', ['timeout' => 4, 'ocrLanguage' => 'eng']);

        $this->assertSame(['/some.pdf', '--ocr-language', 'eng'], $result['args']);
    }

    public function testDefaultsAreBoundedAndQuickTimeoutIsCappedByOverall(): void
    {
        $client = new Client('pdftract');
        $this->assertSame(Client::DEFAULT_TIMEOUT_SECONDS, $client->getTimeoutSeconds());
        $this->assertSame(Client::DEFAULT_QUICK_TIMEOUT_SECONDS, $client->getQuickTimeoutSeconds());
        $this->assertGreaterThan(0.0, $client->getTimeoutSeconds());

        // An overall timeout below the quick default must lower the quick default too.
        $tight = new Client('pdftract', null, 2.0);
        $this->assertSame(2.0, $tight->getQuickTimeoutSeconds());
    }

    public function testZeroTimeoutMeansUnbounded(): void
    {
        $binary = $this->fakeBinary('ok', 'echo json_encode(["ok" => true]);');
        $client = new Client($binary, null, 0.0, 0.0);

        $this->assertSame(0.0, $client->getTimeoutSeconds());
        $this->assertSame(['ok' => true], $client->extract('/some.pdf'));
    }

    public function testNegativeTimeoutIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new Client('pdftract', null, -1.0);
    }

    public function testNonNumericTimeoutOptionIsRejected(): void
    {
        $client = new Client('pdftract', null, 5.0);

        $this->expectException(ConfigurationException::class);
        $client->extract('/some.pdf', ['timeout' => 'soon']);
    }

    public function testLargeStderrDoesNotDeadlock(): void
    {
        // 1 MiB of stderr — far more than a pipe buffer holds. Reading stdout to
        // EOF before touching stderr (the previous behaviour) wedges here.
        $binary = $this->fakeBinary(
            'noisy',
            'fwrite(STDERR, str_repeat("e", 1024 * 1024)); fwrite(STDOUT, "partial"); exit(3);'
        );
        $client = new Client($binary, null, 20.0);

        try {
            $client->extract('/some.pdf');
            $this->fail('Expected PdftractException');
        } catch (PdftractException $e) {
            $this->assertNotInstanceOf(TimeoutException::class, $e, 'Should fail on exit code, not time out');
            $this->assertSame(3, $e->getExitCode());
            $this->assertSame(1024 * 1024, strlen($e->getMessage()));
        }
    }

    public function testStreamYieldsEveryRecord(): void
    {
        $binary = $this->fakeBinary(
            'streamer',
            'foreach ([1, 2, 3] as $i) { echo json_encode(["page" => $i]), "\n"; }'
        );
        $client = new Client($binary, null, 20.0);

        $pages = [];
        foreach ($client->extractStream('/some.pdf') as $record) {
            $pages[] = $record['page'];
        }

        $this->assertSame([1, 2, 3], $pages);
    }

    public function testStreamTimesOutWhenTheChildGoesSilent(): void
    {
        $binary = $this->fakeBinary(
            'stalled',
            'echo json_encode(["page" => 1]), "\n"; @ob_flush(); flush(); sleep(30);'
        );
        $client = new Client($binary, null, 0.5);

        $received = [];
        $start = microtime(true);
        try {
            foreach ($client->extractStream('/some.pdf') as $record) {
                $received[] = $record;
            }
            $this->fail('Expected TimeoutException');
        } catch (TimeoutException $e) {
            $this->assertLessThan(5.0, microtime(true) - $start);
            $this->assertSame([['page' => 1]], $received, 'Records produced before the stall should still be yielded');
        }
    }

    public function testStreamIdleTimeoutDoesNotCutOffASlowButProductiveStream(): void
    {
        // Emits 6 records at ~150ms apart (~0.9s total) with a 0.5s idle bound:
        // a total-time bound would abort this, an idle bound must not.
        $binary = $this->fakeBinary(
            'trickle',
            'for ($i = 1; $i <= 6; $i++) { echo json_encode(["page" => $i]), "\n"; @ob_flush(); flush(); usleep(150000); }'
        );
        $client = new Client($binary, null, 0.5);

        $count = 0;
        foreach ($client->extractStream('/some.pdf') as $record) {
            $count++;
        }

        $this->assertSame(6, $count);
    }

    public function testAbandonedStreamTerminatesTheChild(): void
    {
        $marker = $this->workDir . '/stream-finished';
        $binary = $this->fakeBinary(
            'abandoned',
            'echo json_encode(["page" => 1]), "\n"; @ob_flush(); flush(); sleep(3);'
            . ' file_put_contents(' . var_export($marker, true) . ", 'done');"
        );
        $client = new Client($binary, null, 20.0);

        $stream = $client->extractStream('/some.pdf');
        $this->assertSame(['page' => 1], $stream->current());
        unset($stream); // Consumer walks away mid-stream.

        usleep(4_000_000);
        $this->assertFileDoesNotExist($marker, 'Abandoned stream should not leave a running child behind');
    }
}
