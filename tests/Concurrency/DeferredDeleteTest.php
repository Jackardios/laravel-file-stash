<?php

namespace Jackardios\FileStash\Tests\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deferred deletions: forget() inside a batch callback schedules the
 * deletion, keeps the entry alive for the whole callback, and flushes it
 * under a real exclusive lifecycle lock after the batch releases — without
 * corrupting concurrent readers of the same entry.
 */
#[Group('concurrency')]
class DeferredDeleteTest extends ConcurrencyTestCase
{
    public function testNestedForgetInsideChunkedBatchDefersDeletion(): void
    {
        $server = $this->startSlowServer();
        $urls = [
            $server['base_url'].'/deferred-x.bin?chunks=2',
            $server['base_url'].'/deferred-y.bin?chunks=2',
            $server['base_url'].'/deferred-z.bin?chunks=2',
        ];

        $results = $this->awaitWorkers([
            $this->spawnWorker([
                'op' => 'batch',
                'urls' => $urls,
                'nested' => ['op' => 'forget', 'urls' => [$urls[0]]],
                // Chunked mode: per-file locks are released before the
                // callback, only the lifecycle lock protects the entries.
                'config' => ['batch_chunk_size' => 1],
            ]),
        ], 120.0);

        $result = $results[0];
        $this->assertTrue($result['ok'] ?? false, 'Worker failed: '.$result['_stdout'].$result['_stderr']);

        $nested = $result['results'][0]['nested'][0];
        $this->assertTrue($nested['forgotten'], 'forget() inside a batch callback must report the scheduled deletion.');
        $this->assertTrue($nested['exists_after'], 'The entry must survive the whole batch callback.');

        // After the worker finished (batch + flush): the forgotten entry is
        // gone, the untouched entries remain.
        $this->assertFileDoesNotExist($this->cachePath.'/'.hash('sha256', $urls[0]));
        $this->assertFileExists($this->cachePath.'/'.hash('sha256', $urls[1]));
        $this->assertFileExists($this->cachePath.'/'.hash('sha256', $urls[2]));
        $this->assertSame([], glob($this->cachePath.'/*.tmp') ?: []);
    }

    public function testConcurrentReadersSeeConsistentContentAroundDeferredDelete(): void
    {
        $server = $this->startSlowServer();
        $urlX = $server['base_url'].'/deferred-race.bin?chunks=4&delay_ms=10';
        $urlY = $server['base_url'].'/deferred-other.bin?chunks=2';

        $batchWorker = $this->spawnWorker([
            'op' => 'batch',
            'urls' => [$urlX, $urlY],
            'nested' => ['op' => 'forget', 'urls' => [$urlX]],
            'callback_sleep_ms' => 100,
        ]);

        $readers = [];
        for ($i = 0; $i < 3; $i++) {
            $readers[] = $this->spawnWorker(['op' => 'get', 'urls' => [$urlX], 'iterations' => 8]);
        }

        $results = $this->awaitWorkers([$batchWorker, ...$readers], 180.0);

        foreach ($results as $index => $result) {
            $this->assertTrue($result['ok'] ?? false, "Worker #{$index} failed: ".$result['_stdout'].$result['_stderr']);
        }

        // Readers may hit the entry before the flush, force a re-download
        // after it, or block its deletion with their shared locks — but they
        // must never observe partial or foreign content.
        $expectedSha = hash('sha256', $this->expectedServerBody('/deferred-race.bin', 4));
        foreach (array_slice($results, 1, null, true) as $index => $result) {
            foreach ($result['results'] as $read) {
                $this->assertSame($expectedSha, $read['sha256'], "Reader #{$index} saw corrupted content.");
            }
        }

        $this->assertSame([], glob($this->cachePath.'/*.tmp') ?: []);
    }
}
