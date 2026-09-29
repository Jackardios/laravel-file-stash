<?php

namespace Jackardios\FileStash\Tests\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/**
 * Smoke tests for the multi-process test infrastructure itself.
 */
#[Group('concurrency')]
class ConcurrencyInfraTest extends ConcurrencyTestCase
{
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
