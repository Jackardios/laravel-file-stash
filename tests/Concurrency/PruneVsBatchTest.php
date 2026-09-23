<?php

namespace Jackardios\FileStash\Tests\Concurrency;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * prune() running concurrently with readers must never corrupt reads, and
 * must never delete entries that an open batch() is using.
 */
#[Group('concurrency')]
class PruneVsBatchTest extends ConcurrencyTestCase
{
    public function testPruneUnderPressureNeverCorruptsReads(): void
    {
        $server = $this->startSlowServer();

        $paths = ['/pv-a.bin', '/pv-b.bin', '/pv-c.bin'];
        $urls = array_map(
            static fn (string $path) => $server['base_url'].$path.'?chunks=4&delay_ms=10',
            $paths
        );
        $expectedShas = array_map(
            fn (string $path) => hash('sha256', $this->expectedServerBody($path, 4)),
            $paths
        );

        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $workers[] = $this->spawnWorker(['op' => 'get', 'urls' => $urls, 'iterations' => 6]);
        }
        // max_size=1 makes every unlocked entry an LRU eviction candidate on
        // each run, so prune constantly races the readers.
        $pruneWorker = $this->spawnWorker([
            'op' => 'prune',
            'iterations' => 8,
            'config' => ['max_size' => 1],
        ]);

        $results = $this->awaitWorkers(array_merge($workers, [$pruneWorker]), 180.0);
        $pruneResult = array_pop($results);

        foreach ($results as $index => $result) {
            $this->assertTrue($result['ok'] ?? false, "Reader #{$index} failed: ".$result['_stdout'].$result['_stderr']);

            foreach ($result['results'] as $n => $info) {
                $expectedSha = $expectedShas[$n % count($urls)];
                $this->assertSame(
                    $expectedSha,
                    $info['sha256'],
                    "Reader #{$index} result #{$n} saw a partial or corrupted file."
                );
            }
        }

        $this->assertTrue($pruneResult['ok'] ?? false, 'Prune worker failed: '.$pruneResult['_stdout'].$pruneResult['_stderr']);
    }

    /**
     * Unchunked batches hold a shared lock on every entry for the whole
     * callback; chunked batches release the per-file locks before the
     * callback and hold the shared pin lock instead.
     */
    #[DataProvider('chunkSizeProvider')]
    public function testPruneDoesNotDeleteFilesHeldByOpenBatch(int $chunkSize): void
    {
        $server = $this->startSlowServer();

        $urls = [
            $server['base_url'].'/pv-hold-a.bin?chunks=2',
            $server['base_url'].'/pv-hold-b.bin?chunks=2',
        ];
        $entryPaths = array_map(
            fn (string $url) => $this->cachePath.'/'.hash('sha256', $url),
            $urls
        );

        // 2 files × 3 s sleep = 6 s callback window.
        $batchWorker = $this->spawnWorker([
            'op' => 'batch',
            'urls' => $urls,
            'callback_sleep_ms' => 3000,
            'config' => ['batch_chunk_size' => $chunkSize],
        ]);

        // Entries are published (renamed into place) before the callback runs,
        // so once both exist the batch holds its shared (pin) locks.
        $deadline = microtime(true) + 30.0;
        while (array_filter($entryPaths, 'file_exists') !== $entryPaths) {
            if (microtime(true) > $deadline) {
                $this->killWorker($batchWorker);
                $this->fail('Batch worker did not publish its entries in time.');
            }
            usleep(20000);
        }

        $pruneWorker = $this->spawnWorker([
            'op' => 'prune',
            'config' => ['max_size' => 1],
        ]);
        $pruneResult = $this->awaitWorkers([$pruneWorker], 30.0)[0];
        $this->assertTrue($pruneResult['ok'] ?? false, 'Prune worker failed: '.$pruneResult['_stdout'].$pruneResult['_stderr']);

        // The prune must have finished while the batch still held its locks,
        // otherwise the assertion below would prove nothing.
        $this->assertTrue(
            proc_get_status($batchWorker['proc'])['running'],
            'Batch worker finished before prune completed; increase the callback sleep.'
        );

        foreach ($entryPaths as $entryPath) {
            $this->assertFileExists($entryPath, 'Prune deleted an entry that an open batch() is using.');
        }

        $batchResult = $this->awaitWorkers([$batchWorker], 60.0)[0];
        $this->assertTrue($batchResult['ok'] ?? false, 'Batch worker failed: '.$batchResult['_stdout'].$batchResult['_stderr']);

        foreach ($batchResult['results'][0] as $n => $info) {
            $path = parse_url($urls[$n], PHP_URL_PATH);
            $this->assertSame(
                hash('sha256', $this->expectedServerBody($path, 2)),
                $info['sha256'],
                "Batch file #{$n} was corrupted."
            );
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function chunkSizeProvider(): array
    {
        return [
            'unchunked' => [-1],
            'chunked' => [1],
        ];
    }
}
