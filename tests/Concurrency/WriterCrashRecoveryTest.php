<?php

namespace Jackardios\FileStash\Tests\Concurrency;

use Jackardios\FileStash\FileStash;
use PHPUnit\Framework\Attributes\Group;

/**
 * A writer killed with SIGKILL mid-download must not wedge the cache: the
 * kernel releases its claim lock, so the next worker downloads successfully.
 * The orphaned temp file survives prune within the grace period and is
 * garbage-collected after it.
 */
#[Group('concurrency')]
class WriterCrashRecoveryTest extends ConcurrencyTestCase
{
    public function testCrashedWriterDoesNotBlockOthersAndOrphanIsPruned(): void
    {
        $server = $this->startSlowServer();
        $url = $server['base_url'].'/crash.bin?chunks=40&delay_ms=150';

        // Worker A starts a ~6 s download.
        $workerA = $this->spawnWorker(['op' => 'get', 'urls' => [$url]]);

        // Wait until its temp file has received body bytes, then crash it.
        $deadline = microtime(true) + 30.0;
        $tmpPath = null;
        while (true) {
            clearstatcache();
            $tmpFiles = glob($this->cachePath.'/*.tmp') ?: [];
            if ($tmpFiles !== [] && filesize($tmpFiles[0]) > 0) {
                $tmpPath = $tmpFiles[0];
                break;
            }
            if (microtime(true) > $deadline) {
                $this->killWorker($workerA);
                $this->fail('Writer never started streaming into a temp file.');
            }
            usleep(20000);
        }

        $this->killWorker($workerA);
        $this->assertFileExists($tmpPath, 'The orphaned temp file should remain after the crash.');

        // Worker B must take over: the kernel released the claim lock.
        $workerB = $this->spawnWorker(['op' => 'get', 'urls' => [$url]]);
        $resultB = $this->awaitWorkers([$workerB], 120.0)[0];

        $this->assertTrue($resultB['ok'] ?? false, 'Second worker failed: '.$resultB['_stdout'].$resultB['_stderr']);
        $this->assertSame(
            hash('sha256', $this->expectedServerBody('/crash.bin', 40)),
            $resultB['results'][0]['sha256'],
            'Second worker saw corrupted content.'
        );
        $this->assertSame(
            2,
            $this->serverRequestCount($server['counter_file'], 'GET'),
            'The crashed and the recovering worker should each have issued one download.'
        );

        $entryPath = $this->cachePath.'/'.hash('sha256', $url);
        $this->assertFileExists($entryPath);
        $this->assertFileExists($tmpPath, "Worker B must publish via its own temp file, not reuse A's orphan.");

        $cache = new FileStash(['path' => $this->cachePath]);

        // Within the grace period the orphan is left alone.
        $cache->prune();
        $this->assertFileExists($tmpPath, 'A fresh orphaned temp file must survive prune (grace period).');

        // Past the grace period it is garbage-collected; the entry stays.
        touch($tmpPath, time() - 120);
        $cache->prune();
        $this->assertFileDoesNotExist($tmpPath, 'An expired orphaned temp file must be pruned.');
        $this->assertFileExists($entryPath, 'Prune must not touch the fresh published entry.');
    }
}
