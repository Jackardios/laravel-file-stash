<?php

namespace Jackardios\FileStash\Testing;

use Illuminate\Contracts\Foundation\Application;
use Jackardios\FileStash\Contracts\File;
use Jackardios\FileStash\Contracts\FileStash as FileStashContract;
use Jackardios\FileStash\Support\CacheMetrics;
use Illuminate\Filesystem\Filesystem;

class FileStashFake implements FileStashContract
{
    protected string $path;

    protected CacheMetrics $metrics;

    /**
     * Create a new fake file cache instance.
     *
     * @param Application|null $app Application instance (optional, for compatibility)
     */
    public function __construct(?Application $app = null)
    {
        $storagePath = $app?->storagePath() ?? storage_path();

        (new Filesystem)->cleanDirectory(
            $root = "{$storagePath}/framework/testing/disks/file-stash"
        );

        $this->path = $root;
        $this->metrics = new CacheMetrics();
    }

    /**
     * Get the in-process cache metrics.
     */
    public function metrics(): CacheMetrics
    {
        return $this->metrics;
    }

    /**
     * {@inheritdoc}
     */
    public function get(File $file, ?callable $callback = null, bool $throwOnLock = false)
    {
        $callback = $callback ?? static fn(File $file, string $path): string => $path;

        return $this->batch([$file], function ($files, $paths) use ($callback) {
            return call_user_func($callback, $files[0], $paths[0]);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getOnce(File $file, ?callable $callback = null, bool $throwOnLock = false)
    {
        return $this->get($file, $callback);
    }

    /**
     * {@inheritdoc}
     */
    public function batch(array $files, ?callable $callback = null, bool $throwOnLock = false)
    {
        $callback = $callback ?? static fn(array $files, array $paths): array => $paths;

        $paths = array_map(function ($file) {
            $hash = hash('sha256', $file->getUrl());

            return "{$this->path}/{$hash}";
        }, $files);

        return $callback($files, $paths);
    }

    /**
     * {@inheritdoc}
     */
    public function batchOnce(array $files, ?callable $callback = null, bool $throwOnLock = false)
    {
        return $this->batch($files, $callback);
    }

    /**
     * {@inheritdoc}
     */
    public function prune(): array
    {
        return ['deleted' => 0, 'remaining' => 0, 'total_size' => 0, 'completed' => true];
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function forget(File $file): bool
    {
        $hash = hash('sha256', $file->getUrl());
        $path = "{$this->path}/{$hash}";

        if (!file_exists($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * {@inheritdoc}
     */
    public function exists(File $file): bool
    {
        return false;
    }
}
