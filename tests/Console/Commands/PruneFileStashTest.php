<?php

namespace Jackardios\FileStash\Tests\Console\Commands;

use Jackardios\FileStash\FileStash;
use Jackardios\FileStash\Console\Commands\PruneFileStash;
use Jackardios\FileStash\Tests\TestCase;

class PruneFileStashTest extends TestCase
{
    public function testPruneSuccessOutput()
    {
        $mock = $this->createMock(FileStash::class);
        $mock->method('prune')->willReturn([
            'completed' => true,
            'deleted' => 3,
            'remaining' => 10,
            'total_size' => 2048,
        ]);

        $this->app->instance(FileStash::class, $mock);

        $this->artisan('prune-file-stash')
            ->expectsOutput('File cache pruned successfully.')
            ->expectsOutput('  Deleted: 3 files')
            ->expectsOutput('  Remaining: 10 files')
            ->assertExitCode(0);
    }

    public function testPruneTimeoutOutput()
    {
        $mock = $this->createMock(FileStash::class);
        $mock->method('prune')->willReturn([
            'completed' => false,
            'deleted' => 1,
            'remaining' => 50,
            'total_size' => 4096,
        ]);

        $this->app->instance(FileStash::class, $mock);

        $this->artisan('prune-file-stash')
            ->expectsOutput('Prune operation did not complete (timed out).')
            ->expectsOutput('  Deleted: 1 files')
            ->assertExitCode(0);
    }

    public function testPruneSilentSuppressesOutput()
    {
        $mock = $this->createMock(FileStash::class);
        $mock->method('prune')->willReturn([
            'completed' => true,
            'deleted' => 2,
            'remaining' => 5,
            'total_size' => 1024,
        ]);

        $this->app->instance(FileStash::class, $mock);

        $this->artisan('prune-file-stash --silent')
            ->doesntExpectOutput('File cache pruned successfully.')
            ->doesntExpectOutput('Prune operation did not complete (timed out).')
            ->assertExitCode(0);
    }

    public function testFormatBytesZero()
    {
        $command = new PruneFileStash();
        $method = new \ReflectionMethod($command, 'formatBytes');
        $method->setAccessible(true);

        $this->assertEquals('0 B', $method->invoke($command, 0));
    }

    public function testFormatBytesGigabyte()
    {
        $command = new PruneFileStash();
        $method = new \ReflectionMethod($command, 'formatBytes');
        $method->setAccessible(true);

        $this->assertEquals('1.00 GB', $method->invoke($command, 1073741824));
    }
}
