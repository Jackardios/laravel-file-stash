<?php

namespace Jackardios\FileStash\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Jackardios\FileStash\Contracts\FileStash as FileStashContract;
use Jackardios\FileStash\FileStash;
use Jackardios\FileStash\FileStashServiceProvider;

class FileStashServiceProviderTest extends TestCase
{
    public function testScheduledCommand()
    {
        config(['file-stash.prune_interval' => '*/5 * * * *']);
        $schedule = $this->app[Schedule::class];

        $event = null;
        foreach ($schedule->events() as $scheduledEvent) {
            if (str_contains($scheduledEvent->command, 'file-stash:prune')) {
                $event = $scheduledEvent;
                break;
            }
        }

        $this->assertNotNull($event, 'Scheduled file-stash:prune command was not registered.');
        $this->assertStringContainsString('file-stash:prune', $event->command);
        $this->assertEquals('*/5 * * * *', $event->expression);
    }

    public function testNullPruneIntervalDisablesSchedule()
    {
        config(['file-stash.prune_interval' => null]);

        // Re-run the schedule registration with the disabled interval.
        $provider = new FileStashServiceProvider($this->app);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);

        $provider->registerScheduledPruneCommand();

        $this->assertCount(0, $schedule->events());
    }

    public function testConcreteClassResolvesToSameSingleton()
    {
        config(['file-stash.path' => sys_get_temp_dir().'/file_stash_provider_'.uniqid()]);

        $viaName = $this->app->make('file-stash');
        $viaContract = $this->app->make(FileStashContract::class);
        $viaClass = $this->app->make(FileStash::class);

        $this->assertSame($viaName, $viaContract);
        $this->assertSame($viaName, $viaClass);
    }
}
