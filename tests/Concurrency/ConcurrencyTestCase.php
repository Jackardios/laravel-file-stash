<?php

namespace Jackardios\FileStash\Tests\Concurrency;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Base class for multi-process concurrency tests.
 *
 * Provides helpers to run a deterministic slow HTTP server (PHP built-in
 * server) and to spawn real PHP worker processes executing FileStash
 * operations against a shared cache directory.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    protected string $cachePath;

    protected Filesystem $files;

    /** @var array<int, array{proc: resource, pipes: array<int, resource>}> */
    private array $serverHandles = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Concurrency tests require a POSIX environment.');
        }

        $this->files = new Filesystem;
        $this->cachePath = sys_get_temp_dir().'/file_stash_concurrency_'.uniqid('', true);
        $this->files->makeDirectory($this->cachePath, 0755, true, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->serverHandles as $handle) {
            $status = proc_get_status($handle['proc']);
            // The built-in server forks PHP_CLI_SERVER_WORKERS children; SIGKILL on
            // the master orphans them, and they keep inherited fds (e.g. PHPUnit's
            // stdout pipe) open forever. Kill the children first.
            exec('pkill -KILL -P '.((int) $status['pid']).' 2>/dev/null');
            proc_terminate($handle['proc'], defined('SIGKILL') ? SIGKILL : 9);
            proc_close($handle['proc']);
        }
        $this->serverHandles = [];

        $this->files->deleteDirectory($this->cachePath);

        parent::tearDown();
    }

    /**
     * Start the deterministic slow HTTP server.
     *
     * @return array{host: string, port: int, base_url: string, counter_file: string}
     */
    protected function startSlowServer(int $phpWorkers = 4): array
    {
        $port = $this->findFreePort();
        $counterFile = $this->cachePath.'/.server-requests.log';
        touch($counterFile);

        $command = [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/fixtures/slow-server.php'];
        $env = array_merge($_ENV, getenv(), [
            'SLOW_SERVER_COUNTER' => $counterFile,
            'PHP_CLI_SERVER_WORKERS' => (string) $phpWorkers,
        ]);

        $proc = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, __DIR__.'/fixtures', $env);

        if (! is_resource($proc)) {
            throw new RuntimeException('Failed to start slow server.');
        }

        $this->serverHandles[] = ['proc' => $proc, 'pipes' => $pipes];

        $baseUrl = "http://127.0.0.1:{$port}";
        $this->waitForServer($baseUrl);

        // Reset counter: the readiness probe above is not part of the test.
        file_put_contents($counterFile, '');

        return [
            'host' => '127.0.0.1',
            'port' => $port,
            'base_url' => $baseUrl,
            'counter_file' => $counterFile,
        ];
    }

    /**
     * Spawn a worker process executing a FileStash operation.
     *
     * @param  array<string, mixed>  $task  See tests/Concurrency/fixtures/worker.php
     * @return array{proc: resource, pipes: array<int, resource>, pid: int}
     */
    protected function spawnWorker(array $task): array
    {
        $task['config'] = array_merge(['path' => $this->cachePath], $task['config'] ?? []);

        $command = [PHP_BINARY, __DIR__.'/fixtures/worker.php', base64_encode((string) json_encode($task))];

        $proc = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($proc)) {
            throw new RuntimeException('Failed to spawn worker.');
        }

        $status = proc_get_status($proc);

        return ['proc' => $proc, 'pipes' => $pipes, 'pid' => $status['pid']];
    }

    /**
     * Wait for workers to finish and decode their JSON results.
     *
     * @param  array<int, array{proc: resource, pipes: array<int, resource>, pid: int}>  $workers
     * @return array<int, array{ok?: bool, results?: array<int, mixed>, error?: array{class: string, message: string}, _stdout: string, _stderr: string, _exit: int}>
     */
    protected function awaitWorkers(array $workers, float $timeoutSeconds = 60.0): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $results = [];

        foreach ($workers as $index => $worker) {
            foreach ($worker['pipes'] as $pipe) {
                stream_set_blocking($pipe, false);
            }

            $stdout = '';
            $stderr = '';

            while (true) {
                $stdout .= (string) stream_get_contents($worker['pipes'][1]);
                $stderr .= (string) stream_get_contents($worker['pipes'][2]);

                $status = proc_get_status($worker['proc']);
                if (! $status['running']) {
                    $stdout .= (string) stream_get_contents($worker['pipes'][1]);
                    $stderr .= (string) stream_get_contents($worker['pipes'][2]);
                    break;
                }

                if (microtime(true) > $deadline) {
                    proc_terminate($worker['proc'], defined('SIGKILL') ? SIGKILL : 9);
                    $this->fail("Worker #{$index} timed out after {$timeoutSeconds}s. stderr: {$stderr}");
                }

                usleep(20000);
            }

            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exitCode = proc_close($worker['proc']);

            $decoded = json_decode($stdout, true);
            $result = is_array($decoded) ? $decoded : [];
            $result['_stdout'] = $stdout;
            $result['_stderr'] = $stderr;
            $result['_exit'] = $exitCode;

            $results[$index] = $result;
        }

        return $results;
    }

    /**
     * Kill a worker with SIGKILL (simulating a crash).
     */
    protected function killWorker(array $worker): void
    {
        proc_terminate($worker['proc'], defined('SIGKILL') ? SIGKILL : 9);

        // Wait for the process to actually die.
        $deadline = microtime(true) + 5.0;
        while (proc_get_status($worker['proc'])['running'] && microtime(true) < $deadline) {
            usleep(10000);
        }

        foreach ([1, 2] as $fd) {
            if (is_resource($worker['pipes'][$fd])) {
                fclose($worker['pipes'][$fd]);
            }
        }
        proc_close($worker['proc']);
    }

    /**
     * Count requests handled by the slow server, optionally filtered by method.
     */
    protected function serverRequestCount(string $counterFile, ?string $method = null): int
    {
        $lines = array_filter(explode("\n", trim((string) @file_get_contents($counterFile))), static fn ($l) => $l !== '');

        if ($method !== null) {
            $lines = array_filter($lines, static fn ($l) => str_starts_with($l, $method.' '));
        }

        return count($lines);
    }

    /**
     * Deterministic expected body for a slow-server path (must mirror slow-server.php).
     */
    protected function expectedServerBody(string $path, int $chunks = 8): string
    {
        return str_repeat(str_repeat(hash('sha256', $path), 16), $chunks);
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Failed to find a free port: {$errstr}");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
        if ($port <= 0) {
            throw new RuntimeException('Failed to determine free port.');
        }

        return $port;
    }

    private function waitForServer(string $baseUrl, float $timeoutSeconds = 10.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
            $response = @file_get_contents($baseUrl.'/__ready?chunks=1', false, $context);
            if ($response !== false) {
                return;
            }

            usleep(50000);
        }

        throw new RuntimeException('Slow server did not become ready in time.');
    }
}
