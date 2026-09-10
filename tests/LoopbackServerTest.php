<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use Jedarden\Pdftract\Client;
use Jedarden\Pdftract\Source;
use Jedarden\Pdftract\Tests\Support\LoopbackServer;
use Jedarden\Pdftract\Tests\Support\ScriptedResponse;
use Jedarden\Pdftract\TimeoutException;
use PHPUnit\Framework\TestCase;

/**
 * Smoke coverage for the loopback fixture harness itself
 *
 * The buffered-route and streaming suites lean on this harness for every
 * case they run, so it gets its own proof: each scripted-response shape
 * round-trips through the canonical HTTP client (or a bare curl handle for
 * the shapes that need no client), every request arrives in the record
 * intact, and the whole thing stays offline and deterministic.
 *
 * These cases are deliberately not the client's behavioural coverage —
 * request shape, error mapping and timeout semantics get their own suites
 * against this same harness. When a case here fails, suspect the harness
 * before the client.
 */
final class LoopbackServerTest extends TestCase
{
    private const PDF_BYTES = "%PDF-1.7\n%loopback harness smoke test\n";

    private LoopbackServer $server;

    protected function setUp(): void
    {
        $this->server = LoopbackServer::start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function test_a_scripted_json_response_round_trips_through_the_client(): void
    {
        $this->requireHttpClient();

        $payload = [
            'schema_version' => '1.0',
            'pages' => [['number' => 1, 'text' => 'first page']],
            'diagnostics' => [],
        ];

        $this->server->enqueue(
            ScriptedResponse::json('/extract', $payload)->named('extract happy path'),
        );

        $client = new Client($this->server->baseUri(), 'test-key');

        self::assertSame(
            $payload,
            $client->extract(Source::bytes(self::PDF_BYTES), ['ocrLanguage' => 'eng', 'pages' => '1-3']),
        );

        // The client spoke to the loopback endpoint and nothing else.
        self::assertSame(1, count($this->server->requests()));

        $request = $this->server->lastRequest();
        self::assertNotNull($request);

        self::assertSame('POST', $request->method(), $request->describe());
        self::assertSame('/extract', $request->path(), $request->describe());
        self::assertSame('Bearer test-key', $request->authorization(), $request->describe());
        self::assertNotNull($request->contentType());
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->contentType(), $request->describe());

        // Options are forwarded as multipart fields, the document as an
        // upload carrying the client's filename and exact bytes.
        self::assertSame(
            ['ocr_language' => 'eng', 'pages' => '1-3'],
            $request->fields(),
            $request->describe(),
        );
        self::assertSame('document.pdf', $request->uploadedFilename(), $request->describe());
        self::assertSame(hash('sha256', self::PDF_BYTES), $request->uploadedContentSha256(), $request->describe());
    }

    public function test_a_scripted_text_response_round_trips_through_the_client(): void
    {
        $this->requireHttpClient();

        $this->server->enqueue(
            ScriptedResponse::text("first span\nsecond span\n", '/extract/text'),
        );

        $client = new Client($this->server->baseUri());

        self::assertSame("first span\nsecond span\n", $client->extractText(Source::bytes(self::PDF_BYTES)));
        self::assertSame('/extract/text', $this->server->lastRequest()?->path());
    }

    public function test_streamed_ndjson_records_arrive_in_order_across_delayed_chunks(): void
    {
        $this->requireHttpClient();

        $this->server->enqueue(
            ScriptedResponse::ndjson('/extract/stream', [
                '{"record": 1}',
                '{"record": 2}',
                '{"record": 3}',
            ], delaySeconds: 0.15),
        );

        $client = new Client($this->server->baseUri(), timeoutSeconds: 5.0);

        $startedAt = hrtime(true);
        $records = iterator_to_array($client->extractStream(Source::bytes(self::PDF_BYTES)), false);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1e9;

        self::assertSame([['record' => 1], ['record' => 2], ['record' => 3]], $records);

        // The inter-chunk delays were observed, not collapsed into one
        // write: three 0.15s gaps cannot deliver in less than ~0.3s. Only a
        // lower bound is asserted so a slow machine cannot flake this.
        self::assertGreaterThanOrEqual(0.2, $elapsedSeconds);
    }

    public function test_a_stalled_script_lets_the_client_time_out_and_still_records_the_request(): void
    {
        $this->requireHttpClient();

        // The stall outlives the client's bound by a wide margin: the client
        // must give up on the silence, not wait out the stall.
        $this->server->enqueue(ScriptedResponse::stalled('/extract', stallSeconds: 3.0));

        $client = new Client($this->server->baseUri(), timeoutSeconds: 0.4);

        $startedAt = hrtime(true);
        $timeout = null;

        try {
            $client->extract(Source::bytes(self::PDF_BYTES));
        } catch (TimeoutException $timeout) {
            // Expected: the server never answered.
        }

        $elapsedSeconds = (hrtime(true) - $startedAt) / 1e9;

        self::assertNotNull($timeout, 'extract() should have hit the client timeout against a stalled server');
        self::assertLessThan(2.0, $elapsedSeconds, 'the client waited out the stall instead of timing out');

        // A request the client abandoned was still received and recorded.
        $request = $this->server->lastRequest();
        self::assertNotNull($request, 'the stalled request was not recorded');
        self::assertSame('POST /extract', $request->describe());
        self::assertSame(hash('sha256', self::PDF_BYTES), $request->uploadedContentSha256());
    }

    public function test_unscripted_requests_are_answered_with_a_loud_error_and_still_recorded(): void
    {
        $this->requireCurl();

        [$status, $body] = $this->post('/extract?alpha=1&beta=two', ['X-Loopback: smoke-probe']);

        self::assertSame(500, $status);
        self::assertSame('loopback_no_script', $this->decodeError($body));

        $request = $this->server->lastRequest();
        self::assertNotNull($request);

        self::assertSame('/extract', $request->path(), $request->describe());
        self::assertSame('alpha=1&beta=two', $request->queryString(), $request->describe());
        self::assertSame(['alpha' => '1', 'beta' => 'two'], $request->query(), $request->describe());
        self::assertSame('smoke-probe', $request->header('X-Loopback'), $request->describe());
        self::assertSame(['probe' => '1'], $request->fields(), $request->describe());
    }

    public function test_scripts_are_answered_per_route_in_the_order_queued(): void
    {
        $this->requireCurl();

        $first = ['schema_version' => '1.0', 'pages' => 'first'];
        $second = ['schema_version' => '1.0', 'pages' => 'second'];

        $this->server->enqueue(
            ScriptedResponse::json('/extract', $first),
            ScriptedResponse::json('/extract', $second)->withHeader('X-Loopback-Script', 'second'),
            ScriptedResponse::text('text route', '/extract/text'),
        );

        [$firstStatus, $firstBody, $firstHeaders] = $this->post('/extract');
        [$textStatus, $textBody] = $this->post('/extract/text');
        [$secondStatus, $secondBody, $secondHeaders] = $this->post('/extract');

        // Queues are per route: interleaving a request to another route does
        // not consume the second /extract script.
        self::assertSame(200, $firstStatus);
        self::assertSame(200, $textStatus);
        self::assertSame(200, $secondStatus);
        self::assertSame($first, json_decode($firstBody, true));
        self::assertSame('text route', $textBody);
        self::assertSame($second, json_decode($secondBody, true));

        // Scripted response headers reach the wire verbatim, so a test can
        // tell which script answered. The post() helper keys response
        // headers by their lowercased name, the same way the wire carries
        // them case-insensitively.
        self::assertSame('second', $secondHeaders['x-loopback-script'] ?? null);
        self::assertArrayNotHasKey('x-loopback-script', $firstHeaders);

        // And a third request to an exhausted queue is loud again.
        [$thirdStatus, $thirdBody] = $this->post('/extract');
        self::assertSame(500, $thirdStatus);
        self::assertSame('loopback_no_script', $this->decodeError($thirdBody));
    }

    public function test_the_server_binds_loopback_only_on_an_ephemeral_port(): void
    {
        $host = parse_url($this->server->baseUri(), PHP_URL_HOST);
        $port = parse_url($this->server->baseUri(), PHP_URL_PORT);

        self::assertSame('127.0.0.1', $host, 'the harness must never listen beyond loopback');
        self::assertIsInt($port);
        self::assertGreaterThan(0, $port);
        self::assertLessThanOrEqual(65535, $port);
    }

    /**
     * Skip the case when the canonical HTTP client is not in this tree
     *
     * The harness is transport-agnostic and these cases drive the client to
     * prove a scripted response survives a full round trip. The canonical
     * HTTP Client lands with the transport work that shares this harness, so
     * a tree without it skips rather than fails.
     */
    private function requireHttpClient(): void
    {
        if (!method_exists(Client::class, 'extract')) {
            self::markTestSkipped('The canonical HTTP Client (Client::extract) is not in this tree yet.');
        }

        $this->requireCurl();
    }

    /** The harness and the client both speak HTTP over curl */
    private function requireCurl(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('The curl extension is required for the loopback harness.');
        }
    }

    /**
     * POST a small urlencoded body to the loopback server
     *
     * Enough of a request to exercise recording; not a client, so the
     * harness-only cases stay runnable wherever the harness is.
     *
     * @param array<int, string> $headers Extra header lines
     * @return array{0: int, 1: string, 2: array<string, string>} HTTP status,
     *                                                                response body, and the response headers keyed by lowercase name
     */
    private function post(string $path, array $headers = []): array
    {
        $handle = curl_init($this->server->baseUri() . $path);
        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'probe=1',
            CURLOPT_HTTPHEADER => array_merge(['Expect:'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_TIMEOUT_MS => 5000,
            CURLOPT_HEADERFUNCTION => function ($handle, string $headerLine) use (&$responseHeaders): int {
                [$name, $value] = array_pad(explode(':', $headerLine, 2), 2, '');

                if ($name !== '' && $value !== '') {
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($headerLine);
            },
        ]);

        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            self::fail("The loopback server did not answer POST {$path}: {$error}");
        }

        return [$status, (string)$body, $responseHeaders];
    }

    /** The serve-API-style error code from an error body, for assertions */
    private function decodeError(string $body): ?string
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : null;
    }
}
