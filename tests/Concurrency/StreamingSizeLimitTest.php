<?php

namespace Jackardios\FileStash\Tests\Concurrency;

use Jackardios\FileStash\Exceptions\FileIsTooLargeException;
use PHPUnit\Framework\Attributes\Group;

/**
 * max_file_size through the real curl handler, for a body without a
 * Content-Length: the size can only be enforced while streaming, and the
 * transfer must be aborted as soon as the limit is crossed instead of being
 * downloaded in full and rejected afterwards.
 */
#[Group('concurrency')]
class StreamingSizeLimitTest extends ConcurrencyTestCase
{
    public function testOversizedBodyWithoutContentLengthAbortsTheTransfer(): void
    {
        $server = $this->startSlowServer();

        // 200 KiB streamed over ~4 s; the limit is crossed after 5 chunks.
        $results = $this->awaitWorkers([
            $this->spawnWorker([
                'op' => 'get',
                'urls' => [$server['base_url'].'/unbounded.bin?chunks=200&delay_ms=20&no_length=1'],
                'config' => ['max_file_size' => 4096],
            ]),
        ], 120.0);

        $result = $results[0];
        $this->assertFalse($result['ok'] ?? true, 'The download must fail: '.$result['_stdout']);
        $this->assertSame(FileIsTooLargeException::class, $result['error']['class']);

        $sent = $this->waitForSentChunks($server['counter_file'], '/unbounded.bin');
        $this->assertLessThan(100, $sent, 'curl must abort the transfer instead of downloading all 200 chunks.');

        $this->assertSame([], glob($this->cachePath.'/*') ?: [], 'Neither an entry nor a temp file may remain.');
    }

    /**
     * The server logs the chunks it sent when its script ends, which can
     * lag behind the client's abort by a chunk interval.
     */
    private function waitForSentChunks(string $counterFile, string $path): int
    {
        $deadline = microtime(true) + 10.0;

        do {
            foreach (explode("\n", (string) @file_get_contents($counterFile.'.sent')) as $line) {
                if (str_starts_with($line, $path.' ')) {
                    return (int) substr($line, strlen($path) + 1);
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail("The server never finished serving {$path}.");
    }
}
