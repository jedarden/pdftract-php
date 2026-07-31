<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use Jedarden\Pdftract\Client;
use Jedarden\Pdftract\PdftractException;
use Jedarden\Pdftract\Source;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Characterization tests for the subprocess transport in Client.
 *
 * Every case drives a generated stand-in for the pdftract binary: a shell
 * script that records the argv it was given and then emits a fixed
 * stdout/stderr/exit status. That lets these tests pin the exact CLI wording,
 * output handling, exception messages, and log messages each public method
 * produces, without a real pdftract install.
 *
 * They exist to hold that contract still while the transport underneath it
 * moves — the nine public methods used to hand-roll six near-identical
 * proc_open/pipe-reading blocks and now share one private transport, and
 * ADR-1 (docs/plan/plan.md) proposes replacing proc_open with HTTP entirely.
 */
class ClientTest extends TestCase
{
    private string $tmpDir;
    private string $binaryPath;
    private string $argsPath;

    protected function setUp(): void
    {
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

    // -------------------------------------------------------- extractMarkdown

    public function testExtractMarkdownReturnsStdoutVerbatim(): void
    {
        $client = $this->client("# Title\n\nBody\n");

        $this->assertSame("# Title\n\nBody\n", $client->extractMarkdown('/tmp/doc.pdf'));
    }

    public function testExtractMarkdownPassesMdFlagFirst(): void
    {
        $client = $this->client('md');

        $client->extractMarkdown(Source::file('/tmp/doc.pdf'));

        $this->assertSame(['--md', '/tmp/doc.pdf'], $this->capturedArgs());
    }

    public function testExtractMarkdownThrowsStderrOnNonZeroExit(): void
    {
        $client = $this->client('', 'no such file', 4);

        $this->expectException(PdftractException::class);
        $this->expectExceptionCode(4);
        $this->expectExceptionMessage('no such file');

        $client->extractMarkdown('/tmp/doc.pdf');
    }

    // ---------------------------------------------------------- extractStream

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

    // ----------------------------------------------------------------- search

    public function testSearchYieldsOneDecodedMatchPerLine(): void
    {
        $client = $this->client("{\"page_index\":0,\"text\":\"hit\"}\n{\"page_index\":2,\"text\":\"hit\"}\n");

        $this->assertSame(
            [
                ['page_index' => 0, 'text' => 'hit'],
                ['page_index' => 2, 'text' => 'hit'],
            ],
            iterator_to_array($client->search('/tmp/doc.pdf', 'hit'))
        );
    }

    public function testSearchPassesGrepSubcommandAndPattern(): void
    {
        $client = $this->client("{}\n");

        iterator_to_array(
            $client->search(Source::file('/tmp/doc.pdf'), 'inv.*oice', ['caseInsensitive' => true])
        );

        $this->assertSame(
            ['grep', 'inv.*oice', '/tmp/doc.pdf', '--case-insensitive'],
            $this->capturedArgs()
        );
    }

    public function testSearchThrowsSearchPlaceholderWhenStderrIsEmpty(): void
    {
        $client = $this->client('', '', 1);

        $this->expectException(PdftractException::class);
        $this->expectExceptionMessage('Search command failed with no output');

        iterator_to_array($client->search('/tmp/doc.pdf', 'hit'));
    }

    // ------------------------------------------------ metadata/hash/classify

    public function testGetMetadataPassesMetadataOnlyFlag(): void
    {
        $client = $this->client('{"page_count":3}');

        $this->assertSame(['page_count' => 3], $client->getMetadata(Source::file('/tmp/doc.pdf')));
        $this->assertSame(['--metadata-only', '/tmp/doc.pdf'], $this->capturedArgs());
    }

    public function testHashPassesHashSubcommand(): void
    {
        $client = $this->client('{"hash":"abc","fast_hash":"def"}');

        $this->assertSame(
            ['hash' => 'abc', 'fast_hash' => 'def'],
            $client->hash(Source::file('/tmp/doc.pdf'), ['fast' => true])
        );
        $this->assertSame(['hash', '/tmp/doc.pdf', '--fast'], $this->capturedArgs());
    }

    public function testClassifyPassesClassifySubcommand(): void
    {
        $client = $this->client('{"document_type":"invoice","confidence":0.9}');

        $this->assertSame(
            ['document_type' => 'invoice', 'confidence' => 0.9],
            $client->classify(Source::file('/tmp/doc.pdf'))
        );
        $this->assertSame(['classify', '/tmp/doc.pdf'], $this->capturedArgs());
    }

    // ---------------------------------------------------------- verifyReceipt

    public function testVerifyReceiptReturnsTrueForTrueOutput(): void
    {
        $client = $this->client("true\n");

        $this->assertTrue($client->verifyReceipt('/tmp/doc.pdf', 'receipt-token'));
        $this->assertSame(['verify-receipt', '/tmp/doc.pdf', 'receipt-token'], $this->capturedArgs());
    }

    public function testVerifyReceiptReturnsFalseForAnyOtherOutput(): void
    {
        $this->assertFalse($this->client("false\n")->verifyReceipt('/tmp/doc.pdf', 'r'));
        $this->assertFalse($this->client('')->verifyReceipt('/tmp/doc.pdf', 'r'));
        $this->assertFalse($this->client('TRUE')->verifyReceipt('/tmp/doc.pdf', 'r'));
    }

    public function testVerifyReceiptThrowsPlaceholderWhenStderrIsEmpty(): void
    {
        $client = $this->client('', '', 7);

        $this->expectException(PdftractException::class);
        $this->expectExceptionCode(7);
        $this->expectExceptionMessage('Verify-receipt command failed with no output');

        $client->verifyReceipt('/tmp/doc.pdf', 'r');
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

    public function testSearchLogsUseSearchWording(): void
    {
        $logger = new RecordingLogger();
        $client = $this->client('', 'nope', 1, $logger);

        try {
            iterator_to_array($client->search('/tmp/doc.pdf', 'hit'));
        } catch (PdftractException) {
            // asserted below
        }

        $this->assertSame(
            [
                ['debug', 'Executing pdftract search command'],
                ['error', 'pdftract search command failed'],
            ],
            $logger->messages()
        );
    }

    public function testVerifyReceiptLogsUseVerifyReceiptWording(): void
    {
        $logger = new RecordingLogger();
        $client = $this->client('', 'nope', 1, $logger);

        try {
            $client->verifyReceipt('/tmp/doc.pdf', 'r');
        } catch (PdftractException) {
            // asserted below
        }

        $this->assertSame(
            [
                ['debug', 'Executing pdftract verify-receipt command'],
                ['error', 'pdftract verify-receipt command failed'],
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

    public function testExtractMarkdownLogsUsePlainCommandWording(): void
    {
        $logger = new RecordingLogger();
        $client = $this->client('', 'nope', 1, $logger);

        try {
            $client->extractMarkdown('/tmp/doc.pdf');
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
