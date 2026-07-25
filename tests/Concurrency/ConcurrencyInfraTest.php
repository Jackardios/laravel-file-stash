<?php

namespace Jackardios\FileStash\Tests\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/**
 * Smoke tests for the multi-process test infrastructure itself.
 */
#[Group('concurrency')]
class ConcurrencyInfraTest extends ConcurrencyTestCase
{
    public function testTwoWorkersShareOneDownload(): void
    {
        $server = $this->startSlowServer();
        $url = $server['base_url'].'/infra-smoke.bin?chunks=8&delay_ms=50';

        $workers = [
            $this->spawnWorker(['op' => 'get', 'urls' => [$url]]),
            $this->spawnWorker(['op' => 'get', 'urls' => [$url]]),
        ];

        $results = $this->awaitWorkers($workers);
        $expectedSha = hash('sha256', $this->expectedServerBody('/infra-smoke.bin'));

        foreach ($results as $index => $result) {
            $this->assertTrue($result['ok'] ?? false, "Worker #{$index} failed: ".$result['_stdout'].$result['_stderr']);
            $this->assertSame($expectedSha, $result['results'][0]['sha256'], "Worker #{$index} saw corrupted content.");
        }

        $this->assertSame(
            1,
            $this->serverRequestCount($server['counter_file'], 'GET'),
            'Exactly one worker should have downloaded the file.'
        );
    }

    public function testWorkerReportsErrors(): void
    {
        $server = $this->startSlowServer();
        $url = $server['base_url'].'/missing.bin?status=404';

        $workers = [$this->spawnWorker(['op' => 'get', 'urls' => [$url]])];
        $results = $this->awaitWorkers($workers);

        $this->assertFalse($results[0]['ok'] ?? true);
        $this->assertNotEmpty($results[0]['error']['class'] ?? '');
    }
}
