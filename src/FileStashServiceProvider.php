<?php

namespace Jackardios\FileStash;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Jackardios\FileStash\Console\Commands\PruneFileStash;
use Jackardios\FileStash\Contracts\FileStash as FileStashContract;
use Jackardios\FileStash\Exceptions\InvalidConfigurationException;
use Jackardios\FileStash\Listeners\ClearFileStash;
use Psr\Log\LoggerInterface;

class FileStashServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     */
    public function boot(Dispatcher $events): void
    {
        $this->publishes([
            __DIR__.'/config/file-stash.php' => config_path('file-stash.php'),
        ], ['file-stash-config', 'config']);

        if ($this->app->runningInConsole()) {
            // Lazy: the command is only built when it runs.
            $this->commands([PruneFileStash::class]);
        }

        // Only processes that resolve the scheduler (schedule:run,
        // schedule:list, ...) pay for registering the task.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->registerScheduledPruneCommand($schedule);
        });

        $events->listen('cache:clearing', ClearFileStash::class);
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/file-stash.php', 'file-stash');

        $this->app->singleton('file-stash', function (Application $app): FileStash {
            $raw = $app->make(Repository::class)->get('file-stash');
            $config = [];
            if (is_array($raw)) {
                foreach ($raw as $key => $value) {
                    if (is_string($key)) {
                        $config[$key] = $value;
                    }
                }
            }

            // The event dispatcher is resolved on every dispatch, so
            // Event::fake() also works after the cache was resolved.
            return new FileStash($config, logger: $app->make(LoggerInterface::class));
        });
        $this->app->alias('file-stash', FileStashContract::class);
        // app(FileStash::class) must return the same singleton, not a second instance.
        $this->app->alias('file-stash', FileStash::class);
    }

    /**
     * Register the scheduled command to prune the file cache.
     *
     * An invalid expression is reported instead of thrown: schedule:run must
     * keep running the application's other tasks.
     */
    public function registerScheduledPruneCommand(Schedule $schedule): void
    {
        $expression = $this->app->make(Repository::class)->get('file-stash.prune_interval', '*/5 * * * *');

        // null or false disables the scheduled prune entirely.
        if ($expression === null || $expression === false) {
            return;
        }

        // dragonmantank/cron-expression is what the scheduler evaluates
        // expressions with; illuminate/console only suggests it.
        if (! is_string($expression) || (class_exists(CronExpression::class) && ! CronExpression::isValidExpression($expression))) {
            report(InvalidConfigurationException::create(
                'prune_interval',
                'must be a cron expression, or null to disable the scheduled prune'
            ));

            return;
        }

        $schedule->command(PruneFileStash::class)->cron($expression);
    }
}
