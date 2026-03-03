<?php

namespace Jackardios\FileStash\Tests;

use Illuminate\Console\Scheduling\Schedule;

class FileStashServiceProviderTest extends TestCase
{
    public function testScheduledCommand()
    {
        config(['file-stash.prune_interval' => '*/5 * * * *']);
        $schedule = $this->app[Schedule::class];

        $event = null;
        foreach ($schedule->events() as $scheduledEvent) {
            if (str_contains($scheduledEvent->command, 'prune-file-stash')) {
                $event = $scheduledEvent;
                break;
            }
        }

        $this->assertNotNull($event, 'Scheduled prune-file-stash command was not registered.');
        $this->assertStringContainsString('prune-file-stash', $event->command);
        $this->assertEquals('*/5 * * * *', $event->expression);
    }
}
