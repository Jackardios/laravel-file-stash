<?php

namespace Jackardios\FileStash\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Jackardios\FileStash\Contracts\FileStash as FileStashContract;
use Jackardios\FileStash\Events\CacheMiss;
use Jackardios\FileStash\Exceptions\InvalidConfigurationException;
use Jackardios\FileStash\FileStash;
use Jackardios\FileStash\FileStashServiceProvider;
use Jackardios\FileStash\GenericFile;

class FileStashServiceProviderTest extends TestCase
{
    /**
     * Resolve a fresh scheduler, as `schedule:run` does in a new process.
     */
    protected function freshSchedule(): Schedule
    {
        $this->app->forgetInstance(Schedule::class);

        return $this->app->make(Schedule::class);
    }

    public function testScheduledCommand()
    {
        config(['file-stash.prune_interval' => '*/5 * * * *']);

        $events = array_values(array_filter(
            $this->freshSchedule()->events(),
            static fn ($event) => str_contains((string) $event->command, 'file-stash:prune')
        ));

        $this->assertCount(1, $events, 'Scheduled file-stash:prune command was not registered exactly once.');
        $this->assertSame('*/5 * * * *', $events[0]->expression);
    }

    public function testNullPruneIntervalDisablesSchedule()
    {
        config(['file-stash.prune_interval' => null]);

        $this->assertCount(0, $this->freshSchedule()->events());
    }

    public function testInvalidPruneIntervalIsReportedWithoutBreakingTheScheduler()
    {
        Exceptions::fake();
        config(['file-stash.prune_interval' => 'every five minutes']);

        $schedule = $this->freshSchedule();

        // schedule:run evaluates every event: an invalid expression must not
        // take the application's other scheduled tasks down with it.
        $this->assertSame([], $schedule->dueEvents($this->app)->all());
        Exceptions::assertReported(fn (InvalidConfigurationException $e) => $e->key === 'prune_interval');
    }

    public function testConcreteClassResolvesToSameSingleton()
    {
        config(['file-stash.path' => sys_get_temp_dir().'/file_stash_provider_'.bin2hex(random_bytes(8))]);

        $viaName = $this->app->make('file-stash');
        $viaContract = $this->app->make(FileStashContract::class);
        $viaClass = $this->app->make(FileStash::class);

        $this->assertSame($viaName, $viaContract);
        $this->assertSame($viaName, $viaClass);
    }

    public function testWarningsGoToTheApplicationLogger()
    {
        Log::spy();
        config(['file-stash.path' => $path = "{$this->storagePath}/stash", 'file-stash.lifecycle_lock_timeout' => 0]);
        $this->app['files']->makeDirectory($path, 0777, true, true);
        $this->app['files']->put("{$path}/".hash('sha256', 'fixtures://a.txt'), 'a');

        $lock = fopen("{$path}/.lifecycle.lock", 'c+');
        flock($lock, LOCK_EX);

        try {
            $this->assertFalse($this->app->make('file-stash')->forget(new GenericFile('fixtures://a.txt')));
        } finally {
            fclose($lock);
        }

        Log::shouldHaveReceived('warning')->once();
    }

    public function testEventFakeAfterResolutionStillReceivesEvents()
    {
        config([
            'file-stash.path' => "{$this->storagePath}/stash",
            'file-stash.events_enabled' => true,
            'filesystems.disks.fixtures' => ['driver' => 'local', 'root' => __DIR__.'/files'],
        ]);
        $cache = $this->app->make('file-stash');

        Event::fake();
        $cache->get(new GenericFile('fixtures://test-file.txt'));

        Event::assertDispatched(CacheMiss::class);
    }

    public function testConfigIsPublishedUnderItsOwnTag()
    {
        $expected = [realpath(__DIR__.'/../src/config/file-stash.php') => config_path('file-stash.php')];

        $published = ServiceProvider::pathsToPublish(FileStashServiceProvider::class, 'file-stash-config');
        $this->assertSame($expected, array_combine(array_map('realpath', array_keys($published)), $published));
    }
}
