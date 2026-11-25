<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use Jedarden\Pdftract\AuthenticationException;
use Jedarden\Pdftract\Client;
use Jedarden\Pdftract\ConfigurationException;
use Jedarden\Pdftract\NotFoundException;
use Jedarden\Pdftract\PdftractException;
use Jedarden\Pdftract\TimeoutException;
use Jedarden\Pdftract\Tests\Support\LoopbackServer;
use Jedarden\Pdftract\Tests\Support\ScriptedResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Serve-info route coverage for the canonical HTTP client: the two GET
 * routes — GET /health and the GET / server info banner — end to end
 * against the loopback fixture harness, in the buffered-route suite's
 * style (request shape pinned on the wire the harness records, response
 * mapping pinned on the decoded value).
 *
 * These two routes are the remainder of the audited serve inventory that
 * no client method read (docs/notes/serve-parity-gap.md): the parity
 * matrix's nine SDK methods and the OPS-GATED bf-4bd decision concern the
 * three POST routes and the six missing method routes, so the two GET
 * info routes had no method, no test, and no owner. The decision, taken
 * on bead pdfphp-7feda6fc, is to cover them: GET /health is the
 * connectivity check the SDK's own binary-gated harnesses poll with raw
 * curl (scripts/build-serve-bin.sh's smoke gate,
 * ClientRealServerTest's startup gate), and GET / is the server's
 * self-description — both fixed-200 handlers with no failure mode
 * (Addendum 2026-09-27d), byte-identical upstream from the audited
 * revision eeab77e through current origin/main.
 *
 * What is deliberately NOT covered here: a capability-parsing layer over
 * the banner's free-text `endpoints` strings. Upstream publishes no
 * machine schema for the banner (the parity doc's "OpenAPI finding"), so
 * the client returns it decoded, verbatim — the same contract extract()
 * has to its body.
 *
 * The third GET route, GET /extract, is not a method: it is the
 * file-path guard answering the serve API's only JSON 404, pinned on the
 * real wire by tests/ClientRealServerTest.php. A client would only call
 * it to test the guard, which is the server's behaviour, not the SDK's.
 *
 * The two suites' partition mirrors the buffered/streaming split: this
 * file holds the GET routes' own contract; the real-server suite's GET
 * pins stay the server-side cross-check against the live axum answers,
 * now with the client methods reading the same banners.
 *
 * Every case runs offline: the loopback server answers from scripted
 * responses on an ephemeral 127.0.0.1 port, so nothing here can reach
 * the network beyond loopback.
 */
#[Group('http-client')]
#[Group('serve-info-routes')]
final class ClientServeInfoRouteTest extends TestCase
{
    /**
     * The health body, verbatim to the documented handler (serve.rs
     * health_handler): exactly two fields, `status` fixed "ok" and
     * `version` the binary's package version
     */
    private const HEALTH_BODY = [
        'status' => 'ok',
        'version' => '9.9.9-serve-info-fixture',
    ];

    /**
     * The server info banner, verbatim to the documented handler (serve.rs
     * root_handler): service name, version, and the advertised routes —
     * the same four descriptions, in the server's own order, that the
     * real-server suite pins against the live wire
     */
    private const SERVER_INFO_BODY = [
        'service' => 'pdftract',
        'version' => '9.9.9-serve-info-fixture',
        'endpoints' => [
            'POST /extract - Extract PDF and return JSON',
            'POST /extract/text - Extract PDF and return plain text',
            'POST /extract/stream - Extract PDF and return streaming NDJSON',
            'GET /health - Health check',
        ],
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

    public function test_health_sends_a_bare_get_to_the_health_route(): void
    {
        $this->server->enqueue(
            ScriptedResponse::json('/health', self::HEALTH_BODY, 'GET')->named('health happy path'),
        );

        $client = new Client($this->server->baseUri(), 'secret-key');

        self::assertSame(self::HEALTH_BODY, $client->health());

        $request = $this->server->lastRequest();
        self::assertNotNull($request, 'the request never reached the loopback server');

        self::assertSame('GET', $request->method(), $request->describe());
        self::assertSame('/health', $request->path(), $request->describe());
        self::assertSame('', $request->queryString(), $request->describe());

        // The configured key rides every request, GETs included (the serve
        // API ignores it; an authenticating proxy in front of it does not).
        self::assertSame('Bearer secret-key', $request->authorization(), $request->describe());

        // No document, no multipart body, no fields: the info routes answer
        // from the path alone. The JSON Accept header is the only
        // content negotiation the client does.
        self::assertSame([], $request->fields(), 'a health check must send no form fields');
        self::assertSame([], $request->uploads(), 'a health check must send no upload');
        self::assertSame(0, $request->rawBodyBytes(), 'a health check must send no request body');
        self::assertNull($request->contentType(), 'a bodyless GET must send no Content-Type');
        self::assertSame('application/json', $request->header('Accept'), $request->describe());
    }

    public function test_server_info_sends_a_bare_get_to_the_root_route(): void
    {
        $this->server->enqueue(
            ScriptedResponse::json('/', self::SERVER_INFO_BODY, 'GET')->named('server info happy path'),
        );

        $client = new Client($this->server->baseUri(), 'secret-key');

        self::assertSame(self::SERVER_INFO_BODY, $client->serverInfo());

        $request = $this->server->lastRequest();
        self::assertNotNull($request, 'the request never reached the loopback server');

        self::assertSame('GET', $request->method(), $request->describe());
        self::assertSame('/', $request->path(), $request->describe());
        self::assertSame('', $request->queryString(), $request->describe());
        self::assertSame('Bearer secret-key', $request->authorization(), $request->describe());
        self::assertSame([], $request->fields(), 'a server info call must send no form fields');
        self::assertSame([], $request->uploads(), 'a server info call must send no upload');
        self::assertSame(0, $request->rawBodyBytes(), 'a server info call must send no request body');
        self::assertNull($request->contentType(), 'a bodyless GET must send no Content-Type');
        self::assertSame('application/json', $request->header('Accept'), $request->describe());
    }

    public function test_the_api_key_header_is_omitted_when_no_key_is_configured(): void
    {
        $this->server->enqueue(
            ScriptedResponse::json('/health', self::HEALTH_BODY, 'GET'),
            ScriptedResponse::json('/', self::SERVER_INFO_BODY, 'GET'),
        );

        $client = new Client($this->server->baseUri());
        $client->health();
        $client->serverInfo();

        foreach ($this->server->requests() as $request) {
            self::assertNull(
                $request->authorization(),
                "the client must not send an Authorization header without a configured key ({$request->describe()})",
            );
        }
    }

    // ------------------------------------------------------- response mapping

    public function test_health_maps_the_documented_ok_banner_field_for_field(): void
    {
        // Whole-body assertSame: the mapped value must be exactly the wire's
        // decode — no field added, dropped, renamed, or retyped (the version
        // string here deliberately looks non-semver to catch a caller- or
        // client-side assumption that it is one).
        $this->server->enqueue(ScriptedResponse::json('/health', self::HEALTH_BODY, 'GET'));

        self::assertSame(self::HEALTH_BODY, (new Client($this->server->baseUri()))->health());
    }

    public function test_server_info_maps_the_documented_route_banner_field_for_field(): void
    {
        // Same whole-body pin on the banner: the free-text endpoints list
        // must arrive as the same list, in the server's own order — the
        // client neither parses nor re-sorts it.
        $this->server->enqueue(ScriptedResponse::json('/', self::SERVER_INFO_BODY, 'GET'));

        self::assertSame(self::SERVER_INFO_BODY, (new Client($this->server->baseUri()))->serverInfo());
    }

    public function test_an_undecodable_2xx_body_raises_the_base_exception(): void
    {
        // A 200 whose body is not JSON — a proxy handing back an HTML page
        // under a success status, for instance — cannot be mapped, so the
        // call raises the base class naming the JSON failure, exactly as
        // extract() does for an undecodable extraction payload.
        $this->server->enqueue(
            ScriptedResponse::text('<html>interposed</html>', '/health', 'GET')
                ->withHeader('Content-Type', 'text/html'),
        );

        $exception = null;

        try {
            (new Client($this->server->baseUri()))->health();
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'an undecodable 2xx body must raise');
        self::assertSame(PdftractException::class, get_class($exception));
        self::assertSame(200, $exception->getStatusCode(), 'the response answered 2xx — the status travels unchanged');
        self::assertNull($exception->getErrorCode());
        self::assertStringContainsString('Failed to decode JSON response', $exception->getMessage());
    }

    #[DataProvider('provideProxyStatuses')]
    public function test_a_non_2xx_answer_is_mapped_by_status_alone(
        int $status,
        string $exceptionClass,
    ): void {
        // The two handlers have no upstream failure mode (Addendum
        // 2026-09-27d: fixed 200), so any non-2xx on these routes is a
        // proxy interposing itself — and it maps through the shared
        // status→exception machinery exactly as a buffered POST's failure
        // would: the status alone picks the class, the body's {error,
        // message, hint} fields travel on it.
        $this->server->enqueue(
            ScriptedResponse::error('/health', $status, 'PROXY_ERROR', 'the proxy refused', 'ask the proxy', 'GET'),
        );

        $exception = null;

        try {
            (new Client($this->server->baseUri()))->health();
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, "a {$status} on /health must raise");
        self::assertSame($exceptionClass, get_class($exception), 'the status alone picks the class');
        self::assertSame($status, $exception->getStatusCode());
        self::assertSame($status, $exception->getCode());
        self::assertSame('PROXY_ERROR', $exception->getErrorCode());
        self::assertSame('ask the proxy', $exception->getHint());
        self::assertSame('the proxy refused (hint: ask the proxy)', $exception->getMessage());
    }

    public static function provideProxyStatuses(): array
    {
        return [
            // A proxied endpoint demanding auth on its health check.
            'proxy 401' => [401, AuthenticationException::class],
            // A proxy's own route-not-found under a rewritten path.
            'proxy 404' => [404, NotFoundException::class],
            // Upstream down behind the proxy.
            'proxy 503' => [503, PdftractException::class],
        ];
    }

    // --------------------------------------------------------------- timeouts

    public function test_the_per_call_timeout_option_bounds_the_health_request(): void
    {
        // A 5 second stall against a 0.25 second bound: the health check
        // must give up on its own deadline, not wait the stall out — the
        // whole point of a connectivity check is failing fast.
        $this->server->enqueue(ScriptedResponse::stalled('/health', 5.0, 'GET'));

        $client = new Client($this->server->baseUri(), null, null, 30.0);

        $startedAt = microtime(true);
        $exception = null;

        try {
            $client->health(['timeout' => 0.25]);
        } catch (PdftractException $caught) {
            $exception = $caught;
        }

        $elapsed = microtime(true) - $startedAt;

        self::assertInstanceOf(TimeoutException::class, $exception, 'a request past its per-call bound must raise TimeoutException');
        self::assertInstanceOf(PdftractException::class, $exception);
        self::assertSame(0.25, $exception->getTimeoutSeconds(), 'the exception must report the bound it hit');
        self::assertNull($exception->getStatusCode(), 'a timeout never produced an HTTP response');

        self::assertGreaterThanOrEqual(0.25, $elapsed);
        self::assertLessThan(3.0, $elapsed, 'the client must abort on its deadline, not block for the stall');

        self::assertNotNull($this->server->lastRequest(), 'the timed-out request never reached the loopback server');
    }

    public function test_a_zero_timeout_disables_the_bound(): void
    {
        // 'timeout' => 0 means unbounded, on the GET path exactly as on the
        // buffered POSTs: a slow-but-answering route completes rather than
        // being cut off.
        $this->server->enqueue(
            ScriptedResponse::json('/health', self::HEALTH_BODY, 'GET')->withDelay(0.4),
        );

        self::assertSame(self::HEALTH_BODY, (new Client($this->server->baseUri()))->health(['timeout' => 0]));
    }

    // ------------------------------------------------------------------ options

    public function test_an_option_that_cannot_apply_raises_a_configuration_exception(): void
    {
        // The GET routes read no query string and no form fields, so there
        // is nothing an extraction option could do on them. Rejecting is
        // the contract: dropping would make the call silently mean less
        // than the caller wrote.
        $this->server->enqueue(ScriptedResponse::json('/health', self::HEALTH_BODY, 'GET'));

        $client = new Client($this->server->baseUri());

        $exception = null;

        try {
            $client->health(['pages' => '1-3']);
        } catch (ConfigurationException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'a non-timeout option must be rejected');
        self::assertStringContainsString('pages', $exception->getMessage());
        self::assertStringContainsString('/health', $exception->getMessage());

        // Rejected before anything is sent — the rejection is client-side
        // validation, not a round trip the server has to bounce.
        self::assertNull($this->server->lastRequest(), 'a rejected option must not produce a request');
    }

    public function test_server_info_rejects_non_timeout_options_the_same_way(): void
    {
        $this->server->enqueue(ScriptedResponse::json('/', self::SERVER_INFO_BODY, 'GET'));

        $client = new Client($this->server->baseUri());

        $exception = null;

        try {
            $client->serverInfo(['ocrLanguage' => 'eng']);
        } catch (ConfigurationException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'a non-timeout option must be rejected');
        // The rejection names the option as the caller wrote it: GET routes
        // never reach the field-normalising forwarding path, so there is no
        // snake_case name to report.
        self::assertStringContainsString("option 'ocrLanguage'", $exception->getMessage());
        self::assertStringContainsString('GET /', $exception->getMessage());
        self::assertNull($this->server->lastRequest(), 'a rejected option must not produce a request');
    }

    public function test_an_invalid_timeout_option_raises_a_configuration_exception(): void
    {
        $client = new Client($this->server->baseUri());

        $exception = null;

        try {
            $client->health(['timeout' => 'soon']);
        } catch (ConfigurationException $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'a non-numeric timeout must be rejected');
        self::assertStringContainsString('timeout option', $exception->getMessage());
        self::assertNull($this->server->lastRequest(), 'a rejected option must not produce a request');
    }

    private function requireCurl(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('The curl extension is required for the HTTP client.');
        }
    }
}
