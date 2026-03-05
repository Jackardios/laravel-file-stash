<?php

namespace Jackardios\FileStash\Console\Commands;

use Jackardios\FileStash\Contracts\FileStash as FileStashContract;
use Illuminate\Console\Command;

class PruneFileStash extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prune-file-stash {--silent : Suppress output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove cached files that are too old or exceed the maximum cache size';

    /**
     * Execute the console command.
     */
    public function handle(FileStashContract $cache): int
    {
        $stats = $cache->prune();

        if (!$this->option('silent')) {
            if (!$stats['completed']) {
                $this->warn('Prune operation did not complete (timed out).');
            } else {
                $this->info('File cache pruned successfully.');
            }
            $this->line("  Deleted: {$stats['deleted']} files");
            $this->line("  Remaining: {$stats['remaining']} files");
            $this->line("  Total size: " . $this->formatBytes($stats['total_size']));
        }

        return self::SUCCESS;
    }

    /**
     * Format bytes to human-readable format.
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor(log($bytes, 1024));
        $factor = min($factor, count($units) - 1);

        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }
}
