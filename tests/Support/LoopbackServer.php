<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests\Support;

/**
 * A throwaway stand-in for the pdftract serve endpoint, for one test
 *
 * Starts the PHP built-in cli-server — the same PHP binary the suite runs
 * under, so nothing outside the test has to be installed — bound to
 * 127.0.0.1 on an ephemeral port, with {@see loopback-router.php} answering
 * every request from a queue of {@see ScriptedResponse}s. It never touches
 * the network beyond loopback, needs no real pdftract install, and behaves
 * identically on every run: the scripts a test queues are the only thing
 * that shapes what comes back.
 *
 *     $server = LoopbackServer::start(ScriptedResponse::json('/extract', $payload));
 *     $client = new Client($server->baseUri(), 'test-key');
 *
 *     $client->extract(Source::bytes('%PDF-1.7 test'));
 *
 *     self::assertSame($payload, $client_extract_result);
 *     self::assertSame('document.pdf', $server->lastRequest()?->uploadedFilename());
 *
 * Responses are queued with {@see LoopbackServer::enqueue()}, before or after
 * start — the server re-reads the script queue on every request. Requests to
 * the same method + path are answered with those scripts in the order they
 * were queued; anything else gets a loud 500 `loopback_no_script`.
 *
 * Everything the server receives is recorded and readable through
 * {@see LoopbackServer::requests()} and {@see LoopbackServer::lastRequest()}
 * — including a request that never got a response, such as one the client
 * timed out on.
 *
 * The server handles one request at a time, which keeps the request log's
 * order deterministic. A stalled script occupies the server for its whole
 * stall, so a test that needs a request to overlap a stall should start a
 * second LoopbackServer rather than queue both against one.
 *
 * Tests own the lifecycle: hand the instance to tearDown(), which calls
 * {@see LoopbackServer::stop()} to terminate the server process and delete
 * its scratch directory. stop() is idempotent, and the destructor calls it
 * as a safety net if a test forgets.
 */
final class LoopbackServer
{
    private const STARTUP_TIMEOUT_SECONDS = 10.0;
    private const SHUTDOWN_TIMEOUT_SECONDS = 5.0;

    private const ENV_SPEC = 'PDFTRACT_LOOPBACK_SPEC';
    private const ENV_RECORD = 'PDFTRACT_LOOPBACK_RECORD';
    private const ENV_CONSUMED = 'PDFTRACT_LOOPBACK_CONSUMED';

    /**
     * The cli-server announces the port it bound (the command asks for :0)
     * with this line once it is accepting connections
     */
    private const STARTED_REGEX = '/Development Server \(http:\/\/127\.0\.0\.1:(\d+)\) started/';

    private readonly string $directory;
    private readonly string $specPath;
    private readonly string $recordPath;
    private readonly string $consumedPath;
    private readonly string $serverLogPath;

    /** @var resource|null */
    private $process = null;

    private int $port = 0;

    /** @var list<ScriptedResponse> */
    private array $scripts = [];

    private bool $stopped = false;

    /**
     * Start a loopback server, optionally with its first scripts queued
     *
     * @param ScriptedResponse ...$scripts Responses to queue before the
     *                                     first request, in answer order
     * @throws \RuntimeException If the server process cannot be started or
     *                           does not begin accepting connections
     */
    public static function start(ScriptedResponse ...$scripts): self
    {
        $server = new self();

        try {
            $server->boot();
        } catch (\Throwable $failure) {
            // Never leak a half-started server or its scratch directory.
            $server->stop();

            throw $failure;
        }

        if ($scripts !== []) {
            $server->enqueue(...$scripts);
        }

        return $server;
    }

    private function __construct()
    {
        $directory = sys_get_temp_dir() . '/pdftract-loopback-' . bin2hex(random_bytes(6));

        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Could not create the loopback scratch directory {$directory}");
        }

        $this->directory = $directory;
        $this->specPath = $directory . '/scripts.json';
        $this->recordPath = $directory . '/requests.jsonl';
        $this->consumedPath = $directory . '/consumed.json';
        $this->serverLogPath = $directory . '/server.log';
    }

    /**
     * Queue responses, in the order their routes should be answered with them
     *
     * Safe to call after start: the router re-reads the queue on every
     * request.
     */
    public function enqueue(ScriptedResponse ...$responses): void
    {
        foreach ($responses as $response) {
            $this->scripts[] = $response;
        }

        $this->writeScripts();
    }

    /** The base URL to point a client at: loopback, ephemeral port */
    public function baseUri(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /** The ephemeral port the server bound */
    public function port(): int
    {
        return $this->port;
    }

    /**
     * Every request the server has received, in arrival order
     *
     * @return list<RecordedRequest>
     */
    public function requests(): array
    {
        $requests = [];

        foreach ($this->readRecordLines() as $line) {
            $record = json_decode($line, true);

            if (is_array($record)) {
                $requests[] = RecordedRequest::fromArray($record);
            }
        }

        return $requests;
    }

    /** The most recent request, or null when nothing has been received */
    public function lastRequest(): ?RecordedRequest
    {
        $requests = $this->requests();
        $last = end($requests);

        return $last === false ? null : $last;
    }

    /**
     * Forget every recorded request
     *
     * Script consumption is tracked separately from the log, so this does
     * not change which script the next request to a route gets.
     */
    public function clearRequests(): void
    {
        file_put_contents($this->recordPath, '', LOCK_EX);
    }

    /** How many scripts are currently queued */
    public function queuedScriptCount(): int
    {
        return count($this->scripts);
    }

    /**
     * Terminate the server and delete its scratch directory
     *
     * Idempotent; safe to call from tearDown() unconditionally.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;

        $process = $this->process;
        $this->process = null;

        if (is_resource($process)) {
            $status = proc_get_status($process);
            $pid = (int)($status['pid'] ?? 0);

            proc_terminate($process);

            $deadline = microtime(true) + self::SHUTDOWN_TIMEOUT_SECONDS;

            do {
                $status = proc_get_status($process);

                if (!($status['running'] ?? false)) {
                    break;
                }

                usleep(10_000);
            } while (microtime(true) < $deadline);

            if ($status['running'] ?? false) {
                // SIGTERM was not enough (a request mid-stall is the only
                // way this happens) — the test must not hang on it.
                if ($pid > 0 && function_exists('posix_kill')) {
                    posix_kill($pid, 9);
                }
            }

            proc_close($process);
        }

        $this->removeScratchDirectory();
    }

    /** Do not let a forgotten stop() leak a server process for the suite */
    public function __destruct()
    {
        if (!$this->stopped) {
            $this->stop();
        }
    }

    /**
     * Spawn the cli-server and wait for it to accept connections
     *
     * @throws \RuntimeException When the process dies or never announces a port
     */
    private function boot(): void
    {
        $this->writeScripts();

        // output_buffering/zlib.output_compression off so a streaming chunk
        // reaches the client the moment it is written; generous upload caps
        // so a test can post a real document.
        $command = [
            \PHP_BINARY,
            '-d', 'output_buffering=0',
            '-d', 'zlib.output_compression=0',
            '-d', 'display_errors=0',
            '-d', 'log_errors=1',
            '-d', 'upload_max_filesize=64M',
            '-d', 'post_max_size=64M',
            '-S', '127.0.0.1:0',
            __DIR__ . '/loopback-router.php',
        ];

        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->directory . '/stdout.log', 'a'],
                2 => ['file', $this->serverLogPath, 'a'],
            ],
            $pipes,
            $this->directory,
            $this->childEnvironment(),
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not spawn the loopback cli-server');
        }

        $this->process = $process;

        $deadline = microtime(true) + self::STARTUP_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            if (preg_match(self::STARTED_REGEX, $this->serverLog(), $match) === 1) {
                $this->port = (int)$match[1];

                return;
            }

            if (!(proc_get_status($process)['running'] ?? false)) {
                break;
            }

            usleep(10_000);
        }

        throw new \RuntimeException(sprintf(
            "The loopback cli-server did not start within %.1f seconds.\nServer log:\n%s",
            self::STARTUP_TIMEOUT_SECONDS,
            $this->serverLog(),
        ));
    }

    /**
     * The environment handed to the cli-server process
     *
     * Deliberately minimal. In particular PHP_CLI_SERVER_WORKERS must not
     * leak in from the outer environment: the harness relies on a single
     * worker so requests are handled strictly one at a time and the request
     * log's order is deterministic.
     *
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        return [
            'PATH' => getenv('PATH') ?: '',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
            'TMPDIR' => sys_get_temp_dir(),
            self::ENV_SPEC => $this->specPath,
            self::ENV_RECORD => $this->recordPath,
            self::ENV_CONSUMED => $this->consumedPath,
        ];
    }

    /** Write the script queue where the router re-reads it, atomically */
    private function writeScripts(): void
    {
        $scripts = array_map(static fn (ScriptedResponse $script): array => $script->toArray(), $this->scripts);
        $encoded = json_encode(['scripts' => $scripts], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // rename() is atomic, so a request arriving mid-write never sees a
        // half-written queue.
        $tempPath = $this->specPath . '.tmp';
        file_put_contents($tempPath, $encoded, LOCK_EX);
        rename($tempPath, $this->specPath);
    }

    /** @return list<string> Non-blank lines of the request log */
    private function readRecordLines(): array
    {
        if (!is_file($this->recordPath)) {
            return [];
        }

        $lines = file($this->recordPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lines) ? $lines : [];
    }

    private function serverLog(): string
    {
        return is_file($this->serverLogPath) ? (string)file_get_contents($this->serverLogPath) : '';
    }

    private function removeScratchDirectory(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $remove = static function (string $path) use (&$remove): void {
            if (is_dir($path) && !is_link($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $remove($path . '/' . $entry);
                    }
                }

                @rmdir($path);

                return;
            }

            @unlink($path);
        };

        $remove($this->directory);
    }
}
