<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use Jedarden\Pdftract\Client;
use Jedarden\Pdftract\ConnectionException;
use Jedarden\Pdftract\PdftractException;
use Jedarden\Pdftract\Source;
use Jedarden\Pdftract\TimeoutException;
use Jedarden\Pdftract\Tests\Support\LoopbackServer;
use Jedarden\Pdftract\Tests\Support\ScriptedResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * PSR-3 logging contract for the canonical HTTP client, held as live
 * phpunit cases.
 *
 * Partition rationale (2026-09-27, bead pdfphp-09cd6f90): the PSR-3
 * verification used to live in a standalone script,
 * tests/verify_psr3_logger.php, that drove the retired CLI subprocess
 * transport and was never executed by `./vendor/bin/phpunit` (only
 * *Test.php files are picked up) — a fully green suite could ship with the
 * logging contract broken or silently unchecked. That script is now
 * partitioned, inert, at tests/Retired/verify_psr3_logger.php (bf-4gq), and
 * its assertions are re-held here and in the per-route suites, where the
 * equivalent behaviour is:
 *
 * | Retired script test                       | Re-held where
 * |-------------------------------------------|----------------------------------------------------------
 * | Client accepts a PSR-3 logger             | test_a_request_logs_its_execution_record_to_any_psr3_implementation
 * | DEBUG entries for an invocation           | test_a_request_logs_its_execution_record_to_any_psr3_implementation, buffered suite's test_a_buffered_request_and_its_failure_are_logged
 * | ERROR entries on failure                  | buffered suite (HTTP error branch), test_a_timeout_*, test_a_transport_failure_* here
 * | Client works with the default logger      | test_the_client_defaults_to_a_null_logger_when_none_is_given
 * | Monolog compatibility (optional)          | no Monolog dev dependency — the raw-interface logger here proves the coupling is LoggerInterface alone, which is the property the Monolog check gestured at
 *
 * The logger used here implements the raw PSR-3 interface deliberately, as
 * the retired script's TestLogger did: no AbstractLogger base, so a Client
 * that accidentally required the base class (or any concrete logger type)
 * would fail these cases. Its log() keeps the interface's own signature —
 * PSR-3 declares log() with an untyped $level, and narrowing it (or the
 * message union) is a fatal signature violation, the first of the two bugs
 * the retired script harboured (docs/plan/plan.md, known issues).
 *
 * Every case runs offline: the loopback server answers from scripted
 * responses on an ephemeral 127.0.0.1 port, so nothing here can reach the
 * network beyond loopback.
 */
#[Group('http-client')]
#[Group('psr3-logging')]
final class ClientPsr3LoggerTest extends TestCase
{
    /**
     * A minimal serve-API extraction result
     *
     * extract() maps the decoded JSON body verbatim without schema
     * validation, so a single field is enough to prove a request completed
     * and its response was returned.
     */
    private const DOCUMENT = ['schema_version' => '1.0'];

    /**
     * Fixture document bytes
     *
     * Uploaded as a multipart part; the logging contract does not depend on
     * the content, but NUL bytes and non-ASCII keep the fixture honest
     * about surviving the upload intact.
     */
    private const PDF_BYTES = "%PDF-1.7\n%\x00psr3-logging fixture — naïve bytes\n";

    private LoopbackServer $server;

    protected function setUp(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('The curl extension is required for the HTTP client.');
        }
        $this->server = LoopbackServer::start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    // ------------------------------------------------------- entry acceptance

    public function test_a_request_logs_its_execution_record_to_any_psr3_implementation(): void
    {
        // The retired script's Test 1 and Test 2: a Client accepts a logger
        // that is "just" a LoggerInterface and actually routes its records
        // through it. One record per healthy request: the execution debug
        // entry, with the wire facts in context.
        $logger = new RawPsr3Logger();

        $this->server->enqueue(ScriptedResponse::json('/extract', self::DOCUMENT));

        $client = new Client($this->server->baseUri(), null, $logger, 12.0);

        self::assertSame(self::DOCUMENT, $client->extract(Source::bytes(self::PDF_BYTES)));

        self::assertSame(
            [['debug', 'Executing pdftract request']],
            $logger->messages(),
            'a healthy request logs exactly one debug entry and nothing else',
        );

        // The entry shape the contract promises: level, message, context —
        // and the context carries the request's method, URL, and the bound
        // in effect.
        self::assertSame(
            ['level', 'message', 'context'],
            array_keys($logger->records[0]),
        );
        self::assertSame('debug', $logger->records[0]['level']);

        $context = $logger->records[0]['context'];
        self::assertSame('POST', $context['method']);
        self::assertSame($this->server->baseUri() . '/extract', $context['url']);
        self::assertSame(12.0, $context['timeout']);
    }

    public function test_the_client_defaults_to_a_null_logger_when_none_is_given(): void
    {
        // The retired script's Test 4: `new Client(...)` without a logger
        // must behave identically to one with a logger — the default is a
        // NullLogger, so every log call is a no-op instead of a fatal on
        // null. A healthy request and a failing one both have to run their
        // full course with no logger configured at all.
        $this->server->enqueue(
            ScriptedResponse::json('/extract', self::DOCUMENT),
            ScriptedResponse::error('/extract', 500, 'INTERNAL_ERROR', 'boom'),
        );

        $client = new Client($this->server->baseUri(), null, null, 12.0);

        self::assertSame(self::DOCUMENT, $client->extract(Source::bytes(self::PDF_BYTES)));

        $exception = null;

        try {
            $client->extract(Source::bytes(self::PDF_BYTES));
            self::fail('expected PdftractException');
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(PdftractException::class, $exception);
        self::assertSame(500, $exception->getStatusCode());
    }

    // ---------------------------------------------------------- error records

    public function test_a_timeout_is_logged_as_an_error_record_with_the_bound(): void
    {
        // The transport-failure branch of the retired script's Test 3, for
        // the deadline case: a request past its bound logs the execution
        // debug entry first and then the timeout error entry, whose context
        // names the URL and the bound that was hit.
        $logger = new RawPsr3Logger();

        $this->server->enqueue(ScriptedResponse::stalled('/extract', 5.0));

        $client = new Client($this->server->baseUri(), null, $logger, 12.0);

        $exception = null;

        try {
            $client->extract(Source::bytes(self::PDF_BYTES), ['timeout' => 0.25]);
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(TimeoutException::class, $exception);

        self::assertSame(
            [
                ['debug', 'Executing pdftract request'],
                ['error', 'pdftract request timed out'],
            ],
            $logger->messages(),
            'the debug entry precedes the timeout error entry',
        );

        $context = $logger->records[1]['context'];
        self::assertSame($this->server->baseUri() . '/extract', $context['url']);
        self::assertSame(0.25, $context['timeout']);
    }

    public function test_a_transport_failure_is_logged_as_an_error_record_without_a_status(): void
    {
        // The transport-failure branch of the retired script's Test 3, for
        // the no-response case — the closest analogue of what the script
        // itself exercised (a command that never produced output): the
        // connection dies before any HTTP response, and the error entry
        // carries curl's error string instead of a status.
        $logger = new RawPsr3Logger();

        $deadServer = LoopbackServer::start();
        $baseUri = $deadServer->baseUri();
        $deadServer->stop();

        $client = new Client($baseUri, null, $logger, 12.0);

        $exception = null;

        try {
            $client->extract(Source::bytes(self::PDF_BYTES));
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(ConnectionException::class, $exception);

        self::assertSame(
            [
                ['debug', 'Executing pdftract request'],
                ['error', 'pdftract request failed'],
            ],
            $logger->messages(),
        );

        $context = $logger->records[1]['context'];
        self::assertSame($baseUri . '/extract', $context['url']);
        self::assertArrayHasKey('error', $context, 'the curl error string must be carried');
        self::assertNotSame('', $context['error']);
        self::assertArrayNotHasKey('status', $context, 'a transport failure never reached the server, so there is no status');
        self::assertArrayNotHasKey('timeout', $context, 'a non-timeout transport failure must not borrow the timeout entry shape');
    }
}

/**
 * PSR-3 logger implementing the raw interface, for assertion
 *
 * Deliberately not an AbstractLogger subclass — see the class docblock of
 * {@see ClientPsr3LoggerTest} for why the client must cope with the
 * interface alone.
 */
class RawPsr3Logger implements LoggerInterface
{
    /** @var array<int, array{level: string, message: string, context: array}> */
    public array $records = [];

    public function emergency(\Stringable|string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(\Stringable|string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(\Stringable|string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(\Stringable|string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(\Stringable|string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(\Stringable|string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    // PSR-3 declares log() with an untyped $level; narrowing either parameter
    // is a fatal signature violation, so keep both as the interface has them.
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
