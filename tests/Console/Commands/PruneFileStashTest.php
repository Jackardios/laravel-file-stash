<?php

namespace Jackardios\FileStash\Tests\Console\Commands;

use Jackardios\FileStash\Contracts\FileStash as FileStashContract;
use Jackardios\FileStash\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PruneFileStashTest extends TestCase
{
    protected function tearDown(): void
    {
        // symfony/console < 7.3.3 leaks the verbosity of --silent/--quiet runs
        // into the environment of the whole process.
        putenv('SHELL_VERBOSITY');
        unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);

        parent::tearDown();
    }

    /**
     * @param  array{completed: bool, deleted: int, remaining: int, total_size: int}  $stats
     */
    protected function fakePruneStats(array $stats): void
    {
        $mock = $this->createStub(FileStashContract::class);
        $mock->method('prune')->willReturn($stats);

        $this->app->instance('file-stash', $mock);
    }

    public function testPruneSuccessOutput()
    {
        $this->fakePruneStats(['completed' => true, 'deleted' => 3, 'remaining' => 10, 'total_size' => 2048]);

        $this->artisan('file-stash:prune')
            ->expectsOutput('File cache pruned successfully.')
            ->expectsOutput('  Deleted: 3 files')
            ->expectsOutput('  Remaining: 10 files')
            ->expectsOutput('  Total size: 2.00 KB')
            ->assertExitCode(0);
    }

    public function testPruneTimeoutOutput()
    {
        $this->fakePruneStats(['completed' => false, 'deleted' => 1, 'remaining' => 50, 'total_size' => 1073741824]);

        $this->artisan('file-stash:prune')
            ->expectsOutput('Prune did not complete (timed out, or a chunked batch is using the cache); it continues on the next run.')
            ->expectsOutput('  Deleted: 1 files')
            ->expectsOutput('  Remaining: 50 files')
            ->expectsOutput('  Total size: 1.00 GB')
            ->assertExitCode(0);
    }

    #[DataProvider('sizeProvider')]
    public function testTotalSizeIsHumanReadable(int $bytes, string $formatted)
    {
        $this->fakePruneStats(['completed' => true, 'deleted' => 0, 'remaining' => 1, 'total_size' => $bytes]);

        $this->artisan('file-stash:prune')
            ->expectsOutput("  Total size: {$formatted}")
            ->assertExitCode(0);
    }

    public static function sizeProvider(): array
    {
        return [
            'zero' => [0, '0 B'],
            'one byte' => [1, '1.00 B'],
            'just below a KB' => [1023, '1023.00 B'],
            'fractional KB' => [1536, '1.50 KB'],
            'just below a MB' => [1024 ** 2 - 1, '1024.00 KB'],
            'beyond the largest unit' => [2 * 1024 ** 5, '2048.00 TB'],
        ];
    }

    public function testPruneSilentSuppressesOutput()
    {
        $this->fakePruneStats(['completed' => true, 'deleted' => 2, 'remaining' => 5, 'total_size' => 1024]);

        $this->artisan('file-stash:prune --silent')
            ->doesntExpectOutput()
            ->assertExitCode(0);
    }

    public function testDeprecatedCommandAliasStillWorks()
    {
        $this->fakePruneStats(['completed' => true, 'deleted' => 0, 'remaining' => 0, 'total_size' => 0]);

        // Old v4 name is kept as an alias until v6.
        $this->artisan('prune-file-stash')
            ->expectsOutput('File cache pruned successfully.')
            ->expectsOutput('  Total size: 0 B')
            ->assertExitCode(0);
    }
}
