<?php

namespace Jackardios\FileStash\Tests\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/**
 * Redirect handling through the real curl handler. Guzzle reuses the same
 * sink across redirect hops, and redirect responses may carry a (slow, large)
 * decoy body: the cached file must contain exactly the final body — no
 * trailing decoy bytes — and the decoy must not count against max_file_size.
 */
#[Group('concurrency')]
class RedirectIntegrityTest extends ConcurrencyTestCase
{
    public function testRedirectWithSlowDecoyBodyCachesExactFinalBody(): void
    {
        $server = $this->startSlowServer();

        // Decoy: 8 KiB streamed slowly with the 301; final: 2 KiB document.
        $finalUrl = $server['base_url'].'/redir-final.bin?chunks=2';
        $url = $server['base_url'].'/redir-decoy.bin?chunks=8&delay_ms=30&redirect_status=301&redirect_to='.urlencode($finalUrl);

        $results = $this->awaitWorkers([
            $this->spawnWorker(['op' => 'get', 'urls' => [$url]]),
        ], 120.0);

        $result = $results[0];
        $this->assertTrue($result['ok'] ?? false, 'Worker failed: '.$result['_stdout'].$result['_stderr']);

        $expectedBody = $this->expectedServerBody('/redir-final.bin', 2);
        $this->assertSame(
            hash('sha256', $expectedBody),
            $result['results'][0]['sha256'],
            'Cached file must be byte-identical to the final body (no decoy tail).'
        );
        $this->assertSame(strlen($expectedBody), $result['results'][0]['size']);

        $this->assertSame(2, $this->serverRequestCount($server['counter_file'], 'GET'));
        $this->assertSame([], glob($this->cachePath.'/*.tmp') ?: [], 'No temp files may remain.');
    }

    public function testDecoyBodyDoesNotCountAgainstSizeLimit(): void
    {
        $server = $this->startSlowServer();

        // max_file_size sits above the final body (2 KiB) but below the decoy
        // (8 KiB): the download only succeeds if the decoy is not counted.
        $finalUrl = $server['base_url'].'/redir-limited-final.bin?chunks=2';
        $url = $server['base_url'].'/redir-limited-decoy.bin?chunks=8&redirect_to='.urlencode($finalUrl);

        $results = $this->awaitWorkers([
            $this->spawnWorker([
                'op' => 'get',
                'urls' => [$url],
                'config' => ['max_file_size' => 4096],
            ]),
        ], 120.0);

        $result = $results[0];
        $this->assertTrue($result['ok'] ?? false, 'Worker failed: '.$result['_stdout'].$result['_stderr']);

        $this->assertSame(
            hash('sha256', $this->expectedServerBody('/redir-limited-final.bin', 2)),
            $result['results'][0]['sha256']
        );
    }
}
