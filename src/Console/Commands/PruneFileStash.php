<?php

namespace Jackardios\FileStash\Console\Commands;

use Illuminate\Console\Command;
use Jackardios\FileStash\Contracts\FileStash as FileStashContract;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'file-stash:prune', aliases: ['prune-file-stash'])]
class PruneFileStash extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'file-stash:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove cached files that are too old or exceed the maximum cache size';

    /**
     * The old v4 command name; deprecated, will be removed in v6.
     *
     * @var array<int, string>
     */
    protected $aliases = ['prune-file-stash'];

    /**
     * Execute the console command.
     */
    public function handle(FileStashContract $cache): int
    {
        $stats = $cache->prune();

        // Symfony's global --silent/--quiet options suppress all of this.
        if (! $stats['completed']) {
            $this->warn('Prune did not complete (timed out, or a chunked batch is using the cache); it continues on the next run.');
        } else {
            $this->info('File cache pruned successfully.');
        }
        $this->line("  Deleted: {$stats['deleted']} files");
        $this->line("  Remaining: {$stats['remaining']} files");
        $this->line('  Total size: '.$this->formatBytes($stats['total_size']));

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
        $factor = (int) floor(log($bytes, 1024));
        $factor = min($factor, count($units) - 1);

        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }
}
