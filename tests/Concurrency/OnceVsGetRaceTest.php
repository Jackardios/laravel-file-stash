<?php

namespace Jackardios\FileStash\Tests\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/**
 * getOnce() workers constantly evict the shared entry while get() workers
 * read it. Every callback must still observe a complete file — deletions
 * may only cause re-downloads, never partial reads.
 */
#[Group('concurrency')]
class OnceVsGetRaceTest extends ConcurrencyTestCase
{
    public function testOnceAndGetInterleavingNeverExposesPartialFiles(): void
    {
        $server = $this->startSlowServer();
        $url = $server['base_url'].'/once-race.bin?chunks=4&delay_ms=25';
        $expectedSha = hash('sha256', $this->expectedServerBody('/once-race.bin', 4));

        $workers = [
            $this->spawnWorker(['op' => 'getOnce', 'urls' => [$url], 'iterations' => 6]),
            $this->spawnWorker(['op' => 'getOnce', 'urls' => [$url], 'iterations' => 6]),
            $this->spawnWorker(['op' => 'get', 'urls' => [$url], 'iterations' => 6]),
            $this->spawnWorker(['op' => 'get', 'urls' => [$url], 'iterations' => 6]),
        ];

        $results = $this->awaitWorkers($workers, 120.0);

        foreach ($results as $index => $result) {
            $this->assertTrue($result['ok'] ?? false, "Worker #{$index} failed: ".$result['_stdout'].$result['_stderr']);

            foreach ($result['results'] as $iteration => $info) {
                $this->assertSame(
                    $expectedSha,
                    $info['sha256'],
                    "Worker #{$index} iteration #{$iteration} saw a partial or corrupted file."
                );
            }
        }

        $this->assertSame(
            [],
            glob($this->cachePath.'/*.tmp') ?: [],
            'No temp files may remain after all workers finished.'
        );
    }
}
