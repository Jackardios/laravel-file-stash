<?php

namespace Jackardios\FileStash;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Jackardios\FileStash\Console\Commands\PruneFileStash;
use Jackardios\FileStash\Contracts\FileStash as FileStashContract;
use Jackardios\FileStash\Listeners\ClearFileStash;

class FileStashServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     */
    public function boot(Dispatcher $events): void
    {
        $this->publishes([
            __DIR__.'/config/file-stash.php' => base_path('config/file-stash.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->app->booted([$this, 'registerScheduledPruneCommand']);
        }

        $events->listen('cache:clearing', ClearFileStash::class);
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/file-stash.php', 'file-stash');

        $this->app->singleton('file-stash', function (): FileStash {
            $raw = config('file-stash');
            $config = [];
            if (is_array($raw)) {
                foreach ($raw as $key => $value) {
                    if (is_string($key)) {
                        $config[$key] = $value;
                    }
                }
            }

            return new FileStash($config);
        });
        $this->app->alias('file-stash', FileStashContract::class);
        // app(FileStash::class) must return the same singleton, not a second instance.
        $this->app->alias('file-stash', FileStash::class);

        $this->app->singleton('command.file-stash.prune', function ($app) {
            return new PruneFileStash;
        });
        $this->commands('command.file-stash.prune');
    }

    /**
     * Register the scheduled command to prune the file cache.
     */
    public function registerScheduledPruneCommand(): void
    {
        $expression = config('file-stash.prune_interval', '*/5 * * * *');

        // null or false disables the scheduled prune entirely.
        if ($expression === null || $expression === false) {
            return;
        }

        if (! is_string($expression) || $expression === '') {
            $expression = '*/5 * * * *';
        }

        $this->app->make(Schedule::class)
            ->command(PruneFileStash::class)
            ->cron($expression);
    }
}
