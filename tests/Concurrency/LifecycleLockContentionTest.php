<?php

namespace Jackardios\FileStash\Tests\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/**
 * clear() (lifecycle EX) racing two looping batches (lifecycle SH) must
 * neither deadlock nor expose partial files: cleared entries are simply
 * re-downloaded on the next iteration.
 */
#[Group('concurrency')]
class LifecycleLockContentionTest extends ConcurrencyTestCase
{
    public function testClearRacingTwoBatches(): void
    {
        $server = $this->startSlowServer();

        $paths = ['/lc-a.bin', '/lc-b.bin', '/lc-c.bin'];
        $urls = array_map(
            static fn (string $path) => $server['base_url'].$path.'?chunks=2&delay_ms=10',
            $paths
        );
        $expectedShas = array_map(
            fn (string $path) => hash('sha256', $this->expectedServerBody($path, 2)),
            $paths
        );

        $batchWorkers = [
            $this->spawnWorker([
                'op' => 'batch',
                'urls' => $urls,
                'iterations' => 4,
                'callback_sleep_ms' => 100,
                'config' => ['batch_chunk_size' => -1],
            ]),
            $this->spawnWorker([
                'op' => 'batch',
                'urls' => $urls,
                'iterations' => 4,
                'callback_sleep_ms' => 100,
                'config' => ['batch_chunk_size' => -1],
            ]),
        ];
        $clearWorker = $this->spawnWorker(['op' => 'clear', 'iterations' => 3]);

        $results = $this->awaitWorkers(array_merge($batchWorkers, [$clearWorker]), 180.0);
        $clearResult = array_pop($results);

        foreach ($results as $index => $result) {
            $this->assertTrue($result['ok'] ?? false, "Batch worker #{$index} failed: ".$result['_stdout'].$result['_stderr']);

            foreach ($result['results'] as $iteration => $files) {
                foreach ($files as $n => $info) {
                    $this->assertSame(
                        $expectedShas[$n],
                        $info['sha256'],
                        "Batch worker #{$index} iteration #{$iteration} file #{$n} saw a partial or corrupted file."
                    );
                }
            }
        }

        $this->assertTrue($clearResult['ok'] ?? false, 'Clear worker failed: '.$clearResult['_stdout'].$clearResult['_stderr']);

        $this->assertSame(
            [],
            glob($this->cachePath.'/*.tmp') ?: [],
            'No temp files may remain after all workers finished.'
        );
    }
}
