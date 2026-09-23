<?php

namespace Jackardios\FileStash\Testing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Testing\Fakes\Fake;
use Jackardios\FileStash\Contracts\File;
use Jackardios\FileStash\FileStash;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Test double for the file cache.
 *
 * Extends the real FileStash, so `FileStash::fake()` keeps working for code
 * that type-hints (or resolves from the container) the concrete class, not
 * only the contract. All cache operations are replaced with fast in-memory
 * bookkeeping plus real files on disk — with deterministic content derived
 * from the URL, or content registered via putFake() — so callbacks that
 * read the cached file keep working. Every retrieval is recorded and can be
 * verified with the assert*() helpers. Like the real cache, forget() and
 * getOnce()/batchOnce() cleanup inside a batch callback are deferred until
 * the outermost batch returns.
 *
 * Mirrors Storage::fake(): the fake works in a stable directory under
 * storage/framework/testing (suffixed with the parallel-testing token when
 * present) that is wiped on construction.
 */
class FileStashFake extends FileStash implements Fake
{
    /**
     * Number of retrievals per URL.
     *
     * @var array<string, int>
     */
    protected array $retrieved = [];

    /**
     * Forced exists() results per URL.
     *
     * @var array<string, bool>
     */
    protected array $existsMap = [];

    /**
     * URLs passed to forget().
     *
     * @var array<int, string>
     */
    protected array $forgotten = [];

    /**
     * Custom file content per URL.
     *
     * @var array<string, string>
     */
    protected array $customContent = [];

    /**
     * Nesting depth of running batch callbacks.
     */
    protected int $batchDepth = 0;

    /**
     * Create a new fake file cache instance.
     *
     * @param  Application|null  $app  Application instance (optional, for compatibility)
     */
    public function __construct(?Application $app = null)
    {
        $storagePath = $app?->storagePath() ?? storage_path();
        $path = "{$storagePath}/framework/testing/disks/file-stash".$this->parallelTestingSuffix();

        $files = new Filesystem;
        $files->makeDirectory($path, 0777, true, true);
        $files->cleanDirectory($path);

        parent::__construct(
            ['path' => $path, 'events_enabled' => false],
            null,
            $files
        );
    }

    /**
     * Directory suffix isolating parallel test workers (mirrors Storage::fake()).
     */
    protected function parallelTestingSuffix(): string
    {
        if (! class_exists(ParallelTesting::class)) {
            return '';
        }

        try {
            $token = ParallelTesting::token();
        } catch (Throwable) {
            // No container/facade root available — single-process run.
            return '';
        }

        return $token === false ? '' : "_{$token}";
    }

    /**
     * Register custom content for a URL. get()/batch() will write it into the
     * fake cached file, and exists() will report the URL as existing.
     */
    public function putFake(string $url, string $content): static
    {
        $this->customContent[$url] = $content;

        // An already materialized file gets the new content as well.
        $path = $this->pathFor($url);
        if (file_exists($path)) {
            file_put_contents($path, $content);
        }

        return $this;
    }

    /**
     * Control the result of exists() for a URL.
     */
    public function shouldExist(string $url, bool $exists = true): static
    {
        $this->existsMap[$url] = $exists;

        return $this;
    }

    /**
     * Get the directory the fake writes its files to.
     */
    public function path(): string
    {
        return $this->config['path'];
    }

    /**
     * {@inheritdoc}
     */
    public function get(File $file, ?callable $callback = null, bool $throwOnLock = false)
    {
        $callback = $callback ?? static fn (File $file, string $path): string => $path;

        return $this->batch([$file], function ($files, $paths) use ($callback) {
            return $callback($files[0], $paths[0]);
        }, $throwOnLock);
    }

    /**
     * {@inheritdoc}
     */
    public function getOnce(File $file, ?callable $callback = null, bool $throwOnLock = false)
    {
        $callback = $callback ?? static fn (File $file, string $path): string => $path;

        return $this->batchOnce([$file], function ($files, $paths) use ($callback) {
            return $callback($files[0], $paths[0]);
        }, $throwOnLock);
    }

    /**
     * {@inheritdoc}
     */
    public function batch(array $files, ?callable $callback = null, bool $throwOnLock = false)
    {
        $callback = $callback ?? static fn (array $files, array $paths): array => $paths;

        $paths = array_map(fn (File $file): string => $this->materialize($file), $files);

        $this->batchDepth++;

        try {
            return $callback($files, $paths);
        } finally {
            if (--$this->batchDepth === 0) {
                $this->flushDeferredDeletions();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function batchOnce(array $files, ?callable $callback = null, bool $throwOnLock = false)
    {
        try {
            return $this->batch($files, $callback, $throwOnLock);
        } finally {
            foreach ($files as $file) {
                $this->deleteFake($this->pathFor($file->getUrl()));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function prune(): array
    {
        $entries = glob("{$this->path()}/*") ?: [];
        $totalSize = 0;
        foreach ($entries as $entry) {
            $totalSize += (int) @filesize($entry);
        }

        return ['deleted' => 0, 'remaining' => count($entries), 'total_size' => $totalSize, 'completed' => true];
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        foreach (glob("{$this->path()}/*") ?: [] as $entry) {
            if (@unlink($entry)) {
                $this->metrics->evictions++;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function forget(File $file): bool
    {
        $url = $file->getUrl();
        $this->forgotten[] = $url;

        $path = $this->pathFor($url);
        if (! file_exists($path)) {
            return false;
        }

        return $this->deleteFake($path);
    }

    /**
     * {@inheritdoc}
     */
    public function exists(File $file): bool
    {
        $url = $file->getUrl();

        if (array_key_exists($url, $this->existsMap)) {
            return $this->existsMap[$url];
        }

        // URLs with registered content or already-retrieved files exist by default.
        return isset($this->customContent[$url]) || file_exists($this->pathFor($url));
    }

    /**
     * Assert that the given URL was retrieved at least once.
     */
    public function assertRetrieved(string $url): void
    {
        Assert::assertArrayHasKey(
            $url,
            $this->retrieved,
            "Expected file [{$url}] to be retrieved, but it was not."
        );
    }

    /**
     * Assert that the given URL was retrieved exactly $times times.
     */
    public function assertRetrievedTimes(string $url, int $times): void
    {
        $actual = $this->retrieved[$url] ?? 0;

        Assert::assertSame(
            $times,
            $actual,
            "Expected file [{$url}] to be retrieved {$times} time(s), but it was retrieved {$actual} time(s)."
        );
    }

    /**
     * Assert that the given URL was never retrieved.
     */
    public function assertNotRetrieved(string $url): void
    {
        Assert::assertArrayNotHasKey(
            $url,
            $this->retrieved,
            "Expected file [{$url}] not to be retrieved, but it was."
        );
    }

    /**
     * Assert that no files were retrieved at all.
     */
    public function assertNothingRetrieved(): void
    {
        Assert::assertSame(
            [],
            $this->retrieved,
            'Expected no files to be retrieved, but '.count($this->retrieved).' were.'
        );
    }

    /**
     * Assert that forget() was called for the given URL.
     */
    public function assertForgotten(string $url): void
    {
        Assert::assertContains(
            $url,
            $this->forgotten,
            "Expected file [{$url}] to be forgotten, but it was not."
        );
    }

    /**
     * Create the fake cached file for a URL and record the retrieval.
     */
    protected function materialize(File $file): string
    {
        $url = $file->getUrl();
        $path = $this->pathFor($url);

        $this->retrieved[$url] = ($this->retrieved[$url] ?? 0) + 1;

        if (file_exists($path)) {
            $this->metrics->hits++;
        } else {
            $this->metrics->misses++;
            $this->metrics->retrievals++;
            file_put_contents($path, $this->customContent[$url] ?? "fake-content:{$url}");
        }

        return $path;
    }

    /**
     * Delete a fake cached file, or queue the deletion while a batch
     * callback runs (the file may still be in use there).
     */
    protected function deleteFake(string $path): bool
    {
        if ($this->batchDepth > 0) {
            $this->deferredDeletions[] = ['path' => $path, 'reason' => 'deferred'];

            return true;
        }

        if (file_exists($path) && @unlink($path)) {
            $this->metrics->evictions++;

            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    protected function flushDeferredDeletions(): void
    {
        $deletions = $this->deferredDeletions;
        $this->deferredDeletions = [];

        foreach (array_unique(array_column($deletions, 'path')) as $path) {
            $this->deleteFake($path);
        }
    }

    /**
     * Deterministic fake cache path for a URL.
     */
    protected function pathFor(string $url): string
    {
        return "{$this->path()}/".hash('sha256', $url);
    }
}
