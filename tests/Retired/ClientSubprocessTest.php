<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests\Retired;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * SUPERSEDED — retired with the CLI subprocess transport. Never run these
 * cases against the canonical HTTP client.
 *
 * Partition rationale (2026-09-10): these are the subprocess-behaviour cases
 * of the old tests/ClientTest.php, which characterized
 * Jedarden\Pdftract\Client as a proc_open wrapper around a local pdftract
 * binary. Each one drove a generated stand-in for the pdftract binary — a
 * shell script recording the argv it was given, then emitting a fixed
 * stdout/stderr/exit status — and pinned the exact CLI wording, output
 * handling, exception messages, and log messages the wrapper produced.
 *
 * ADR-1 (docs/plan/plan.md) replaced that transport with HTTP against
 * `pdftract --serve`, and PSR-4 now resolves Jedarden\Pdftract\Client to the
 * canonical HTTP client, so this contract no longer describes the shipped
 * code. Per bf-4gq the legacy suite was partitioned rather than deleted and
 * these cases are retired with the transport they tested.
 *
 * The partition is inert, by construction:
 *
 * - phpunit.xml excludes the `retired-cli-subprocess` group from the default
 *   suite, so vendor/bin/phpunit never runs it.
 * - setUp() skips every case before any body executes.
 * - No case references the canonical client. The Client, Source, and
 *   PdftractException names appearing in the bodies are deliberately left
 *   unqualified and unresolved: when written they named
 *   Jedarden\Pdftract\{Client,Source,PdftractException} under src/Pdftract,
 *   whose namespace the PSR-4 root now resolves to the canonical HTTP client
 *   instead. Importing those names here would silently re-point this record
 *   at the wrong class, so the bodies are preserved verbatim as a record and
 *   never execute. Do not add use statements for them.
 *
 * To read this contract as live code, check out a revision from before the
 * ADR-1 HTTP-client migration (git log -- tests/ClientTest.php).
 */
#[Group('retired-cli-subprocess')]
class ClientSubprocessTest extends TestCase
{
    /** Why every case in this partition refuses to run. */
    private const RETIRED =
        'Retired with the CLI subprocess transport (ADR-1 in docs/plan/plan.md,'
        . ' bf-4gq): preserved as a record of the old contract; not run against'
        . ' the HTTP client.';

    private string $tmpDir;
    private string $binaryPath;
    private string $argsPath;

    protected function setUp(): void
    {
        $this->markTestSkipped(self::RETIRED);

        $this->tmpDir = sys_get_temp_dir() . '/pdftract-php-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700);
        $this->binaryPath = $this->tmpDir . '/pdftract';
        $this->argsPath = $this->tmpDir . '/argv';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
    }

    /**
     * Writes the stand-in binary and returns a client wired to it.
     */
    private function client(
        string $stdout = '',
        string $stderr = '',
        int $exitCode = 0,
        ?AbstractLogger $logger = null
    ): Client {
        $script = '#!/bin/sh' . "\n"
            . 'printf \'%s\n\' "$@" > ' . escapeshellarg($this->argsPath) . "\n"
            . 'printf %s \'' . base64_encode($stdout) . '\' | base64 -d' . "\n"
            . 'printf %s \'' . base64_encode($stderr) . '\' | base64 -d >&2' . "\n"
            . 'exit ' . $exitCode . "\n";

        file_put_contents($this->binaryPath, $script);
        chmod($this->binaryPath, 0700);

        return new Client($this->binaryPath, $logger);
    }

    /**
     * The argv the stand-in binary was invoked with, one element per argument.
     *
     * @return array<int, string>
     */
    private function capturedArgs(): array
    {
        $raw = file_get_contents($this->argsPath);
        $this->assertNotFalse($raw, 'binary stand-in did not record its arguments');

        $raw = rtrim($raw, "\n");

        return $raw === '' ? [] : explode("\n", $raw);
    }

    // ---------------------------------------------------------------- extract

    public function testExtractDecodesJsonOutput(): void
    {
        $client = $this->client('{"schema_version":"1.0","pages":[{"page_index":0}]}');

        $this->assertSame(
            ['schema_version' => '1.0', 'pages' => [['page_index' => 0]]],
            $client->extract('/tmp/doc.pdf')
        );
    }

    public function testExtractPassesSourceAndOptionsAsCliArgs(): void
    {
        $client = $this->client('{}');

        $client->extract(Source::file('/tmp/doc.pdf'), [
            'ocrLanguage' => 'eng',
            'fast' => true,
            'skipped' => false,
            'ignored' => null,
            'maxPages' => 12,
        ]);

        $this->assertSame(
            ['/tmp/doc.pdf', '--ocr-language', 'eng', '--fast', '--max-pages', '12'],
            $this->capturedArgs()
        );
    }

    public function testExtractPassesUrlSourceAsUrlFlag(): void
    {
        $client = $this->client('{}');

        $client->extract(Source::url('https://example.com/doc.pdf'));

        $this->assertSame(['--url', 'https://example.com/doc.pdf'], $this->capturedArgs());
    }

    public function testExtractPassesStdinSourceAsDash(): void
    {
        $client = $this->client('{}');

        $client->extract(Source::stdin());

        $this->assertSame(['-'], $this->capturedArgs());
    }

    public function testExtractThrowsOnUndecodableOutput(): void
    {
        $client = $this->client('this is not json');

        $this->expectException(PdftractException::class);
        $this->expectExceptionCode(-1);
        $this->expectExceptionMessage('Failed to decode JSON output: ');

        $client->extract('/tmp/doc.pdf');
    }

    public function testExtractThrowsStderrOnNonZeroExit(): void
    {
        $client = $this->client('', 'pdf is encrypted', 3);

        $this->expectException(PdftractException::class);
        $this->expectExceptionCode(3);
        $this->expectExceptionMessage('pdf is encrypted');

        $client->extract('/tmp/doc.pdf');
    }

    public function testExtractThrowsPlaceholderWhenStderrIsEmpty(): void
    {
        $client = $this->client('', '', 1);

        $this->expectException(PdftractException::class);
        $this->expectExceptionCode(1);
        $this->expectExceptionMessage('Command failed with no output');

        $client->extract('/tmp/doc.pdf');
    }

    // ------------------------------------------------------------ extractText

    public function testExtractTextReturnsStdoutVerbatim(): void
    {
        $client = $this->client("Hello\nWorld\n");

        $this->assertSame("Hello\nWorld\n", $client->extractText('/tmp/doc.pdf'));
    }

    public function testExtractTextPassesTextFlagFirst(): void
    {
        $client = $this->client('text');

        $client->extractText(Source::file('/tmp/doc.pdf'), ['ocrLanguage' => 'eng']);

        $this->assertSame(['--text', '/tmp/doc.pdf', '--ocr-language', 'eng'], $this->capturedArgs());
    }

    public function testExtractTextThrowsPlaceholderWhenStderrIsEmpty(): void
    {
        $client = $this->client('', '', 2);

        $this->expectException(PdftractException::class);
        $this->expectExceptionCode(2);
        $this->expectExceptionMessage('Command failed with no output');

        $client->extractText('/tmp/doc.pdf');
    }

    // -------------------------------------------------------- extractStream

    public function testExtractStreamYieldsOneDecodedObjectPerLine(): void
    {
        $client = $this->client("{\"page_index\":0}\n{\"page_index\":1}\n");

        $this->assertSame(
            [['page_index' => 0], ['page_index' => 1]],
            iterator_to_array($client->extractStream('/tmp/doc.pdf'))
        );
    }

    public function testExtractStreamSkipsBlankAndUndecodableLines(): void
    {
        $client = $this->client("{\"page_index\":0}\n\n   \nnot json\n{\"page_index\":1}\n");

        $this->assertSame(
            [['page_index' => 0], ['page_index' => 1]],
            iterator_to_array($client->extractStream('/tmp/doc.pdf'))
        );
    }

    public function testExtractStreamPassesArgsWithoutExtraFlags(): void
    {
        $client = $this->client("{}\n");

        iterator_to_array($client->extractStream(Source::file('/tmp/doc.pdf'), ['fast' => true]));

        $this->assertSame(['/tmp/doc.pdf', '--fast'], $this->capturedArgs());
    }

    public function testExtractStreamThrowsStderrAfterYieldingOnNonZeroExit(): void
    {
        $client = $this->client("{\"page_index\":0}\n", 'stream blew up', 5);

        $yielded = [];

        try {
            foreach ($client->extractStream('/tmp/doc.pdf') as $item) {
                $yielded[] = $item;
            }
            $this->fail('expected PdftractException');
        } catch (PdftractException $e) {
            $this->assertSame('stream blew up', $e->getMessage());
            $this->assertSame(5, $e->getCode());
        }

        $this->assertSame([['page_index' => 0]], $yielded);
    }

    public function testExtractStreamThrowsStreamPlaceholderWhenStderrIsEmpty(): void
    {
        $client = $this->client('', '', 1);

        $this->expectException(PdftractException::class);
        $this->expectExceptionMessage('Stream command failed with no output');

        iterator_to_array($client->extractStream('/tmp/doc.pdf'));
    }

    // ---------------------------------------------------------------- logging

    public function testExtractLogsTheCommandItRuns(): void
    {
        $logger = new RecordingLogger();
        $client = $this->client('{}', '', 0, $logger);

        $client->getMetadata('/tmp/doc.pdf');

        $this->assertSame(
            [['debug', 'Executing pdftract command']],
            $logger->messages()
        );
        $this->assertSame(
            $this->binaryPath . " '--metadata-only' '/tmp/doc.pdf'",
            $logger->records[0]['context']['command']
        );
    }

    public function testFailedCommandLogsExitCodeAndStderr(): void
    {
        $logger = new RecordingLogger();
        $client = $this->client('', 'kaboom', 9, $logger);

        try {
            $client->extract('/tmp/doc.pdf');
        } catch (PdftractException) {
            // asserted below
        }

        $this->assertSame(
            [['debug', 'Executing pdftract command'], ['error', 'pdftract command failed']],
            $logger->messages()
        );
        $context = $logger->records[1]['context'];
        $this->assertSame(9, $context['exit_code']);
        $this->assertSame('kaboom', $context['stderr']);
        $this->assertSame($this->binaryPath . " '/tmp/doc.pdf'", $context['command']);
    }

    public function testUndecodableOutputIsLoggedWithTheJsonError(): void
    {
        $logger = new RecordingLogger();
        $client = $this->client('not json', '', 0, $logger);

        try {
            $client->extract('/tmp/doc.pdf');
        } catch (PdftractException) {
            // asserted below
        }

        $this->assertSame(
            [['debug', 'Executing pdftract command'], ['error', 'Failed to decode JSON output']],
            $logger->messages()
        );
        $context = $logger->records[1]['context'];
        $this->assertSame($this->binaryPath . " '/tmp/doc.pdf'", $context['command']);
        $this->assertSame('Syntax error', $context['json_error']);
    }

    public function testStreamLogsUseStreamWording(): void
    {
        $logger = new RecordingLogger();
        $client = $this->client('', 'nope', 1, $logger);

        try {
            iterator_to_array($client->extractStream('/tmp/doc.pdf'));
        } catch (PdftractException) {
            // asserted below
        }

        $this->assertSame(
            [
                ['debug', 'Executing pdftract stream command'],
                ['error', 'pdftract stream command failed'],
            ],
            $logger->messages()
        );
    }

    public function testExtractTextLogsUsePlainCommandWording(): void
    {
        $logger = new RecordingLogger();
        $client = $this->client('', 'nope', 1, $logger);

        try {
            $client->extractText('/tmp/doc.pdf');
        } catch (PdftractException) {
            // asserted below
        }

        $this->assertSame(
            [['debug', 'Executing pdftract command'], ['error', 'pdftract command failed']],
            $logger->messages()
        );
    }

    public function testNoLoggerIsRequired(): void
    {
        $client = $this->client('{"ok":true}');

        $this->assertSame(['ok' => true], $client->extract('/tmp/doc.pdf'));
    }
}

/**
 * PSR-3 logger that keeps every record for assertion.
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
            $this->records
        );
    }
}
