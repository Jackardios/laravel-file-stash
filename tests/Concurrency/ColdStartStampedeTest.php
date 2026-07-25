<?php

namespace Jackardios\FileStash\Tests\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/**
 * Eight workers hit the same cold URL at once. The claim lock must ensure
 * exactly one download, every worker must see the complete file, and no
 * temp files may remain afterwards.
 */
#[Group('concurrency')]
class ColdStartStampedeTest extends ConcurrencyTestCase
{
    public function testEightWorkersColdUrlSingleDownload(): void
    {
        $server = $this->startSlowServer();
        $url = $server['base_url'].'/stampede.bin?chunks=16&delay_ms=100';

        $workers = [];
        for ($i = 0; $i < 8; $i++) {
            $workers[] = $this->spawnWorker(['op' => 'get', 'urls' => [$url]]);
        }

        $results = $this->awaitWorkers($workers, 120.0);
        $expectedSha = hash('sha256', $this->expectedServerBody('/stampede.bin', 16));

        foreach ($results as $index => $result) {
            $this->assertTrue($result['ok'] ?? false, "Worker #{$index} failed: ".$result['_stdout'].$result['_stderr']);
            $this->assertSame(
                $expectedSha,
                $result['results'][0]['sha256'],
                "Worker #{$index} saw corrupted or partial content."
            );
        }

        $this->assertSame(
            1,
            $this->serverRequestCount($server['counter_file'], 'GET'),
            'Exactly one worker should have downloaded the file.'
        );

        $this->assertSame(
            [],
            glob($this->cachePath.'/*.tmp') ?: [],
            'No temp files may remain after all workers finished.'
        );
    }
}
