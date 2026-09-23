<?php

namespace Jackardios\FileStash;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Jackardios\FileStash\Contracts\File;
use Jackardios\FileStash\Contracts\FileStash as FileStashContract;
use Jackardios\FileStash\Events\CacheFileEvicted;
use Jackardios\FileStash\Events\CacheFileRetrieved;
use Jackardios\FileStash\Events\CacheHit;
use Jackardios\FileStash\Events\CacheMiss;
use Jackardios\FileStash\Events\CachePruneCompleted;
use Jackardios\FileStash\Exceptions\DiskNotAllowedException;
use Jackardios\FileStash\Exceptions\FailedToRetrieveFileException;
use Jackardios\FileStash\Exceptions\FileIsTooLargeException;
use Jackardios\FileStash\Exceptions\FileLockedException;
use Jackardios\FileStash\Exceptions\HostNotAllowedException;
use Jackardios\FileStash\Exceptions\LifecycleLockTimeoutException;
use Jackardios\FileStash\Exceptions\MimeTypeIsNotAllowedException;
use Jackardios\FileStash\Exceptions\SourceResourceIsInvalidException;
use Jackardios\FileStash\Exceptions\SourceResourceTimedOutException;
use Jackardios\FileStash\Http\RemoteFetcher;
use Jackardios\FileStash\Support\BatchResult;
use Jackardios\FileStash\Support\CacheMetrics;
use Jackardios\FileStash\Support\ConfigNormalizer;
use Jackardios\FileStash\Support\DeleteResult;
use Jackardios\FileStash\Support\LockManager;
use Jackardios\FileStash\Support\MimeGuard;
use Jackardios\FileStash\Support\Url;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The file cache.
 *
 * @phpstan-import-type NormalizedConfig from ConfigNormalizer
 *
 * @phpstan-type RetrievedFile array{path: string, stream: resource}
 */
class FileStash implements FileStashContract
{
    /**
     * Grace period before an orphaned temp file may be pruned, in seconds.
     *
     * Live downloads hold an exclusive lock on their temp file, so the grace
     * only needs to cover the microsecond window between creating and locking
     * it.
     */
    protected const TEMP_GRACE_SECONDS = 60;

    /**
     * Basename pattern of cache entries ({sha256}).
     */
    protected const ENTRY_FILE_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * Basename pattern of write-in-progress temp files ({sha256}.{pid}.{16hex}.tmp).
     */
    protected const TEMP_FILE_PATTERN = '/^[0-9a-f]{64}\.\d+\.[0-9a-f]{16}\.tmp$/';

    /**
     * @var NormalizedConfig
     */
    protected array $config;

    /**
     * HTTP layer for remote file operations.
     */
    protected RemoteFetcher $remoteFetcher;

    /**
     * Filesystem helper.
     */
    protected Filesystem $files;

    /**
     * Filesystem manager for storage disks. Resolved lazily on the first
     * disk:// access, so the class works standalone for HTTP(S) URLs.
     */
    protected ?FilesystemManager $storage;

    /**
     * Logger instance for diagnostic logging.
     */
    protected LoggerInterface $logger;

    /**
     * Event dispatcher for cache events. Without an injected one, the
     * container's dispatcher is looked up on every dispatch, so swapping it
     * later (Event::fake()) takes effect.
     */
    protected ?Dispatcher $dispatcher;

    /**
     * In-process cache metrics.
     */
    protected CacheMetrics $metrics;

    /**
     * Entry deletions queued while this process holds a shared lifecycle
     * lock (forget()/once-cleanup inside a batch callback); flushed under a
     * real exclusive lock right after the outermost shared frame releases.
     *
     * @var list<array{path: string, reason: string}>
     */
    protected array $deferredDeletions = [];

    /**
     * Create an instance.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        array $config = [],
        ?Client $client = null,
        ?Filesystem $files = null,
        ?FilesystemManager $storage = null,
        ?LoggerInterface $logger = null,
        ?Dispatcher $dispatcher = null
    ) {
        $this->config = ConfigNormalizer::normalize($config);
        $this->files = $files ?? new Filesystem;
        $this->storage = $storage;
        $this->logger = $logger ?: new NullLogger;
        $this->remoteFetcher = new RemoteFetcher($this->config, $client, $this->logger);
        $this->dispatcher = $dispatcher;
        $this->metrics = new CacheMetrics;
    }

    /**
     * Get the filesystem manager for storage disks, resolving it from the
     * Laravel container on first use.
     *
     * @throws RuntimeException When no manager was injected and no Laravel
     *                          container is available (standalone usage with disk:// URLs).
     */
    protected function storage(): FilesystemManager
    {
        if ($this->storage !== null) {
            return $this->storage;
        }

        try {
            $filesystem = app('filesystem');
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Storage disk URLs (disk://path) require a filesystem manager. '
                .'Pass an Illuminate\\Filesystem\\FilesystemManager to the FileStash constructor '
                .'when using the cache outside of Laravel.',
                0,
                $exception
            );
        }

        if (! $filesystem instanceof FilesystemManager) {
            throw new RuntimeException('The "filesystem" service must resolve to Illuminate\\Filesystem\\FilesystemManager.');
        }

        return $this->storage = $filesystem;
    }

    protected function resolveEventDispatcher(): ?Dispatcher
    {
        $container = Container::getInstance();
        if (! $container->bound(Dispatcher::class)) {
            return null;
        }

        return $container->make(Dispatcher::class);
    }

    /**
     * Get the in-process cache metrics.
     */
    public function metrics(): CacheMetrics
    {
        return $this->metrics;
    }

    /**
     * Dispatch a cache event when events are enabled.
     */
    protected function dispatchEvent(object $event): void
    {
        if (! $this->config['events_enabled']) {
            return;
        }

        ($this->dispatcher ?? $this->resolveEventDispatcher())?->dispatch($event);
    }

    /**
     * Remove a specific file from the cache.
     *
     * Inside a batch()/batchOnce() callback the entry may be in active use
     * by this very process, so the deletion is deferred: it happens under a
     * real exclusive lifecycle lock right after the batch releases its
     * shared lock, and `true` means "scheduled".
     *
     * @param  File  $file  The file to remove from cache
     * @return bool True when the file was deleted (or scheduled for deletion
     *              inside a batch callback); false when it didn't exist, is
     *              in use, or the lifecycle lock timed out
     */
    public function forget(File $file): bool
    {
        $cachedPath = $this->getCachedPath($file);

        // Checked before acquiring the lifecycle lock: opening the lock file
        // would otherwise create the cache directory as a side effect.
        if (! $this->files->exists($cachedPath)) {
            return false;
        }

        if ($this->isNestedInSharedLifecycle()) {
            $this->deferDeletion($cachedPath, 'forgotten');

            return true;
        }

        try {
            return (bool) $this->withLifecycleExclusiveLock(
                fn (): bool => $this->deleteEntry($cachedPath, 'forgotten') === DeleteResult::Deleted
            );
        } catch (LifecycleLockTimeoutException $exception) {
            $this->logger->warning('Could not acquire the lifecycle lock to forget a cached file.', [
                'path' => $cachedPath,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws GuzzleException
     * @throws FailedToRetrieveFileException
     * @throws MimeTypeIsNotAllowedException
     * @throws FileIsTooLargeException
     * @throws HostNotAllowedException
     */
    public function exists(File $file): bool
    {
        return Url::isRemote($file->getUrl())
            ? $this->remoteFetcher->exists($file)
            : $this->existsDisk($file);
    }

    /**
     * {@inheritdoc}
     *
     * @throws GuzzleException
     * @throws FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     * @throws FileLockedException
     * @throws FailedToRetrieveFileException
     * @throws LifecycleLockTimeoutException
     */
    public function get(File $file, ?callable $callback = null, bool $throwOnLock = false)
    {
        $callback = $callback ?? static fn (File $file, string $path): string => $path;

        return $this->batch(
            [$file],
            static fn (array $files, array $paths) => $callback($files[0], $paths[0]),
            $throwOnLock
        );
    }

    /**
     * {@inheritdoc}
     *
     * @throws GuzzleException
     * @throws FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     * @throws FileLockedException
     * @throws FailedToRetrieveFileException
     * @throws LifecycleLockTimeoutException
     */
    public function getOnce(File $file, ?callable $callback = null, bool $throwOnLock = false)
    {
        $callback = $callback ?? static fn (File $file, string $path): string => $path;

        return $this->batchOnce(
            [$file],
            static fn (array $files, array $paths) => $callback($files[0], $paths[0]),
            $throwOnLock
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<int, File>  $files
     *
     * @throws GuzzleException
     * @throws FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     * @throws FileLockedException
     * @throws FailedToRetrieveFileException
     * @throws LifecycleLockTimeoutException
     */
    public function batch(array $files, ?callable $callback = null, bool $throwOnLock = false)
    {
        $callback = $callback ?? static fn (array $files, array $paths): array => $paths;

        $batch = $this->runBatch($files, $callback, $throwOnLock);

        if ($batch->exception !== null) {
            throw $batch->exception;
        }

        return $batch->result;
    }

    /**
     * Retrieve all files and run the callback under a shared lifecycle lock.
     *
     * When the number of files exceeds batch_chunk_size, retrieval happens in
     * chunks and the per-file shared locks of a chunk are released before the
     * next chunk starts (bounding open file descriptors). In that mode the
     * shared pin lock — taken before the first chunk and held through the
     * callback — keeps prune() from evicting entries, and the lifecycle lock
     * keeps clear()/forget() of other workers out; only a deletion outside
     * the lock protocol could remove the files.
     *
     * @param  array<int, File>  $files
     * @param  callable(array<int, File>, array<int, string>): mixed  $callback
     */
    protected function runBatch(array $files, callable $callback, bool $throwOnLock): BatchResult
    {
        /** @var BatchResult */
        return $this->withLifecycleSharedLock(function () use ($files, $callback, $throwOnLock): BatchResult {
            $chunkSize = $this->config['batch_chunk_size'];
            $isChunked = $chunkSize >= 1 && count($files) > $chunkSize;

            /** @var array<int, string> $paths */
            $paths = [];
            /** @var array<int, RetrievedFile> $retrieved */
            $retrieved = [];
            $pin = $isChunked ? $this->acquirePinLock() : null;

            try {
                /** @var array<int, array<int, File>> $chunks */
                $chunks = $isChunked ? array_chunk($files, $chunkSize, true) : [$files];

                foreach ($chunks as $chunkFiles) {
                    foreach ($chunkFiles as $index => $file) {
                        $retrieved[$index] = $this->retrieve($file, $throwOnLock);
                        $paths[$index] = $retrieved[$index]['path'];
                    }

                    if ($isChunked) {
                        $this->closeRetrievedStreams($retrieved);
                        $retrieved = [];
                    }
                }

                return new BatchResult($callback($files, $paths), $paths);
            } catch (\Throwable $exception) {
                return new BatchResult(null, $paths, $exception);
            } finally {
                $this->closeRetrievedStreams($retrieved);

                if ($pin !== null) {
                    flock($pin, LOCK_UN);
                    fclose($pin);
                }
            }
        });
    }

    /**
     * Take the pin lock shared for a chunked batch.
     *
     * prune() holds it exclusively only while deleting a single entry, so
     * the wait is short; it is bounded by lifecycle_lock_timeout anyway.
     *
     * @return resource
     *
     * @throws LifecycleLockTimeoutException
     */
    protected function acquirePinLock()
    {
        $pin = LockManager::openLockFile($this->getPinLockPath());

        if (! LockManager::flockWithTimeout($pin, LOCK_SH, $this->config['lifecycle_lock_timeout'])) {
            fclose($pin);
            throw LifecycleLockTimeoutException::create(
                "Failed to acquire file cache pin lock within {$this->config['lifecycle_lock_timeout']} seconds."
            );
        }

        return $pin;
    }

    /**
     * Path of the pin lock that chunked batches hold shared for their whole
     * callback, and prune() takes exclusively for each eviction.
     */
    protected function getPinLockPath(): string
    {
        return $this->config['path'].'/.pin.lock';
    }

    /**
     * Close the streams of retrieved files, releasing their shared locks.
     *
     * @param  array<int, RetrievedFile>  $retrieved
     */
    protected function closeRetrievedStreams(array $retrieved): void
    {
        foreach ($retrieved as $entry) {
            if (is_resource($entry['stream'])) {
                fclose($entry['stream']);
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<int, File>  $files
     *
     * @throws GuzzleException
     * @throws FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     * @throws FileLockedException
     * @throws FailedToRetrieveFileException
     * @throws LifecycleLockTimeoutException
     */
    public function batchOnce(array $files, ?callable $callback = null, bool $throwOnLock = false)
    {
        $callback = $callback ?? static fn (array $files, array $paths): array => $paths;

        $batch = $this->runBatch($files, $callback, $throwOnLock);

        $pathsToDelete = array_values(array_unique($batch->paths));

        if (! empty($pathsToDelete)) {
            if ($this->isNestedInSharedLifecycle()) {
                // batchOnce inside an outer batch: the files may still be in
                // use by the outer callback — defer until its lock releases.
                foreach ($pathsToDelete as $path) {
                    $this->deferDeletion($path, 'once');
                }
            } else {
                try {
                    $this->withLifecycleExclusiveLock(function () use ($pathsToDelete) {
                        foreach ($pathsToDelete as $path) {
                            $this->deleteEntry($path, 'once');
                        }
                    });
                } catch (RuntimeException $exception) {
                    // The cleanup is best effort: failing the caller after
                    // its callback already ran would invite a retry of work
                    // that is done. The entries are left for prune().
                    $this->logger->warning('Could not delete cached files after batchOnce(); leaving them to prune().', [
                        'paths_count' => count($pathsToDelete),
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        if ($batch->exception !== null) {
            throw $batch->exception;
        }

        return $batch->result;
    }

    /**
     * {@inheritdoc}
     *
     * @return array{deleted: int, remaining: int, total_size: int, completed: bool} Statistics about pruning operation
     *
     * @throws LifecycleLockTimeoutException
     */
    public function prune(): array
    {
        // Checked before acquiring the lifecycle lock: opening the lock file
        // would otherwise create the cache directory as a side effect.
        if (! $this->files->exists($this->config['path'])) {
            $this->dispatchEvent(new CachePruneCompleted(0, 0, 0, true));

            return ['deleted' => 0, 'remaining' => 0, 'total_size' => 0, 'completed' => true];
        }

        /** @var array{deleted: int, remaining: int, total_size: int, completed: bool} $stats */
        $stats = $this->withLifecycleSharedLock(function (): array {
            $stats = ['deleted' => 0, 'remaining' => 0, 'total_size' => 0, 'completed' => true];

            if (! $this->files->exists($this->config['path'])) {
                return $stats;
            }

            $startTime = time();
            $timeout = $this->config['prune_timeout'];
            $now = time();
            $allowedAge = $this->config['max_age'] * 60;
            $allowedSize = $this->config['max_size'];

            $fileInfos = [];
            $tempFiles = [];

            foreach ($this->findCacheFiles() as $file) {
                if ($this->isPruneTimedOut($startTime, $timeout, 'file collection')) {
                    $stats['completed'] = false;
                    break;
                }

                try {
                    // Write-in-progress temp files are not cache entries: they
                    // are garbage-collected separately and never counted.
                    if (preg_match(self::TEMP_FILE_PATTERN, $file->getBasename()) === 1) {
                        $tempFiles[] = [
                            'path' => $file->getPathname(),
                            'mtime' => $file->getMTime(),
                        ];

                        continue;
                    }

                    $fileInfos[] = [
                        'path' => $file->getPathname(),
                        'atime' => $file->getATime(),
                        'size' => $file->getSize(),
                    ];
                } catch (RuntimeException $e) {
                    continue;
                }
            }

            usort($fileInfos, static fn ($a, $b) => $a['atime'] <=> $b['atime']);

            // Totals are computed upfront so an early timeout still reports
            // honest numbers for the files collected so far.
            $totalSize = array_sum(array_column($fileInfos, 'size'));
            $remainingCount = count($fileInfos);
            $remainingFiles = [];
            $pin = LockManager::openLockFile($this->getPinLockPath());

            try {
                if ($stats['completed']) {
                    foreach ($fileInfos as $info) {
                        if ($this->isPruneTimedOut($startTime, $timeout, 'age-based pruning')) {
                            $stats['completed'] = false;
                            break;
                        }

                        $isExpired = ($now - $info['atime']) > $allowedAge;

                        if (! $isExpired) {
                            $remainingFiles[] = $info;

                            continue;
                        }

                        $result = $this->evict($pin, $info['path'], $info['atime'], 'pruned_age');

                        if ($result === null) {
                            $stats['completed'] = false;
                            break;
                        }

                        if ($result === DeleteResult::Skipped) {
                            $remainingFiles[] = $info;

                            continue;
                        }

                        // Deleted by us or already gone: either way it no
                        // longer occupies the cache.
                        $remainingCount--;
                        $totalSize -= $info['size'];

                        if ($result === DeleteResult::Deleted) {
                            $stats['deleted']++;
                        }
                    }
                }

                if ($stats['completed'] && $totalSize > $allowedSize) {
                    foreach ($remainingFiles as $info) {
                        if ($totalSize <= $allowedSize) {
                            break;
                        }

                        if ($this->isPruneTimedOut($startTime, $timeout, 'size-based pruning', $totalSize - $allowedSize)) {
                            $stats['completed'] = false;
                            break;
                        }

                        $result = $this->evict($pin, $info['path'], $info['atime'], 'pruned_size');

                        if ($result === null) {
                            $stats['completed'] = false;
                            break;
                        }

                        if ($result === DeleteResult::Skipped) {
                            continue;
                        }

                        $remainingCount--;
                        $totalSize -= $info['size'];

                        if ($result === DeleteResult::Deleted) {
                            $stats['deleted']++;
                        }
                    }
                }
            } finally {
                fclose($pin);
            }

            // Garbage-collect temp files orphaned by crashed writers. Live
            // downloads hold an exclusive lock, so unlinkLocked skips them;
            // the grace period covers the moment between fopen and flock.
            // Infrastructure cleanup — no eviction events or metrics.
            foreach ($tempFiles as $temp) {
                if ($this->isPruneTimedOut($startTime, $timeout, 'temp file cleanup')) {
                    $stats['completed'] = false;
                    break;
                }

                if (($now - $temp['mtime']) <= self::TEMP_GRACE_SECONDS) {
                    continue;
                }

                $this->unlinkLocked($temp['path']);
            }

            if (! $this->pruneClaimFiles($startTime, $timeout)) {
                $stats['completed'] = false;
            }

            $stats['total_size'] = $totalSize;
            $stats['remaining'] = $remainingCount;

            return $stats;
        });

        $this->dispatchEvent(new CachePruneCompleted(
            $stats['deleted'],
            $stats['remaining'],
            $stats['total_size'],
            $stats['completed']
        ));

        return $stats;
    }

    /**
     * Evict one entry for prune(), unless a chunked batch is running.
     *
     * Chunked batches release their per-file locks before the callback and
     * hold the pin lock shared instead; prune() takes it exclusively around
     * every single deletion, so a batch starting mid-prune only waits for
     * one deletion, and prune stops evicting as soon as a batch holds it.
     *
     * @param  resource  $pin
     * @return DeleteResult|null Null when a chunked batch holds the pin lock.
     */
    protected function evict($pin, string $path, int $atime, string $reason): ?DeleteResult
    {
        if (! flock($pin, LOCK_EX | LOCK_NB)) {
            $this->logger->info('Prune stopped evicting: a chunked batch is using the cache.', [
                'path' => $this->config['path'],
            ]);

            return null;
        }

        try {
            return $this->deleteEntry($path, $reason, $this->unreadSince($atime));
        } finally {
            flock($pin, LOCK_UN);
        }
    }

    /**
     * Deletion guard for prune(): the decision to evict an entry was made on
     * the atime collected before the deletion; a worker that read (touched)
     * the entry in between makes it recently used, so it is kept.
     *
     * @return callable(array<string, mixed>): bool
     */
    protected function unreadSince(int $collectedAtime): callable
    {
        return static fn (array $stat): bool => is_int($stat['atime'] ?? null) && $stat['atime'] <= $collectedAtime;
    }

    /**
     * Check if prune operation has timed out.
     *
     * @param  int  $startTime  Start time of the prune operation
     * @param  int  $timeout  Timeout in seconds (0 or negative = no timeout)
     * @param  string  $phase  Current pruning phase for logging
     * @param  int|null  $remainingSize  Remaining size to prune (for logging)
     * @return bool True if timed out
     */
    protected function isPruneTimedOut(int $startTime, int $timeout, string $phase, ?int $remainingSize = null): bool
    {
        if ($timeout <= 0) {
            return false;
        }

        $elapsed = time() - $startTime;
        if ($elapsed <= $timeout) {
            return false;
        }

        $context = [
            'timeout' => $timeout,
            'elapsed' => $elapsed,
        ];

        if ($remainingSize !== null) {
            $context['remaining_size'] = $remainingSize;
        }

        $this->logger->warning("Prune operation timed out during {$phase}", $context);

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @throws LifecycleLockTimeoutException
     */
    public function clear(): void
    {
        // Checked before acquiring the lifecycle lock: opening the lock file
        // would otherwise create the cache directory as a side effect.
        if (! $this->files->exists($this->config['path'])) {
            return;
        }

        $this->withLifecycleExclusiveLock(function () {
            if (! $this->files->exists($this->config['path'])) {
                return;
            }

            foreach ($this->findCacheFiles() as $file) {
                // With the exclusive lifecycle lock held there are no active
                // downloads: temp files are orphans of crashed writers (no
                // eviction events for them), and all claim files are idle.
                if (preg_match(self::TEMP_FILE_PATTERN, $file->getBasename()) === 1) {
                    $this->unlinkLocked($file->getPathname());
                } else {
                    $this->deleteEntry($file->getPathname(), 'cleared');
                }
            }

            $this->pruneClaimFiles(time(), -1);
        });
    }

    /**
     * Cache entries and temp files directly inside the cache directory.
     *
     * Anything else — dot-files (locks), subdirectories, files with foreign
     * names — is not ours: prune()/clear() never touch it, even when the
     * cache path points at a directory shared with other data.
     *
     * @return iterable<SplFileInfo>
     */
    protected function findCacheFiles(): iterable
    {
        return Finder::create()
            ->files()
            ->depth(0)
            ->ignoreDotFiles(true)
            ->name(self::ENTRY_FILE_PATTERN)
            ->name(self::TEMP_FILE_PATTERN)
            ->in($this->config['path']);
    }

    /**
     * Garbage-collect idle download claim files.
     *
     * A claim is deleted only when its exclusive lock can be taken (no active
     * downloader or waiter) and the path still points at the locked inode.
     * Waiters that lose their claim inode to this GC detect it via an nlink
     * recheck and reopen the claim.
     *
     * @return bool False when the operation timed out.
     */
    protected function pruneClaimFiles(int $startTime, int $timeout): bool
    {
        $claims = glob("{$this->config['path']}/.locks/*.lock");
        if (! is_array($claims)) {
            return true;
        }

        foreach ($claims as $claimPath) {
            if ($this->isPruneTimedOut($startTime, $timeout, 'claim cleanup')) {
                return false;
            }

            // Infrastructure cleanup — no eviction events or metrics.
            $this->unlinkLocked($claimPath);
        }

        return true;
    }

    /**
     * Execute callback while holding a shared lifecycle lock.
     *
     * The shared lock keeps prune/clear and one-time deletion from removing
     * files while they are in active use.
     *
     * @return mixed
     *
     * @throws LifecycleLockTimeoutException
     */
    protected function withLifecycleSharedLock(callable $callback)
    {
        return LockManager::withLifecycleLock(
            $this->getLifecycleLockPath(),
            LOCK_SH,
            $this->config['lifecycle_lock_timeout'],
            $callback
        );
    }

    /**
     * Execute callback while holding an exclusive lifecycle lock.
     *
     * @return mixed
     *
     * @throws LifecycleLockTimeoutException
     * @throws \LogicException When this process holds a shared lifecycle lock
     *                         (deletions from inside a shared section must go
     *                         through deferDeletion() instead).
     */
    protected function withLifecycleExclusiveLock(callable $callback)
    {
        return LockManager::withLifecycleLock(
            $this->getLifecycleLockPath(),
            LOCK_EX,
            $this->config['lifecycle_lock_timeout'],
            $callback
        );
    }

    /**
     * Whether this process currently holds a shared lifecycle lock for this
     * cache (i.e. we are inside a batch()/batchOnce()/prune() section).
     */
    protected function isNestedInSharedLifecycle(): bool
    {
        return LockManager::heldLifecycleType($this->getLifecycleLockPath()) === LOCK_SH;
    }

    /**
     * Queue an entry deletion until the outermost shared lifecycle frame of
     * this process releases, then flush the queue under a real exclusive
     * lifecycle lock. Eviction metrics/events fire at flush time.
     */
    protected function deferDeletion(string $path, string $reason): void
    {
        $this->deferredDeletions[] = ['path' => $path, 'reason' => $reason];

        LockManager::onOutermostRelease(
            $this->getLifecycleLockPath(),
            spl_object_id($this),
            function (): void {
                $this->flushDeferredDeletions();
            }
        );
    }

    /**
     * Delete all queued entries under an exclusive lifecycle lock.
     *
     * The queue is detached first, so re-entrant deferrals start a fresh
     * queue. On a lock timeout the entries stay on disk for prune() to
     * reclaim later.
     */
    protected function flushDeferredDeletions(): void
    {
        $deletions = $this->deferredDeletions;
        $this->deferredDeletions = [];

        if ($deletions === []) {
            return;
        }

        try {
            $this->withLifecycleExclusiveLock(function () use ($deletions): void {
                foreach ($deletions as $deletion) {
                    $this->deleteEntry($deletion['path'], $deletion['reason']);
                }
            });
        } catch (LifecycleLockTimeoutException $exception) {
            $this->logger->warning('Could not flush deferred cache deletions; leaving them to prune().', [
                'paths_count' => count($deletions),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Get path for the lifecycle lock file (inside the cache directory, so it
     * shares permissions and lifetime with the cache itself).
     */
    protected function getLifecycleLockPath(): string
    {
        return $this->config['path'].'/.lifecycle.lock';
    }

    /**
     * Check for existence of a file from a storage disk.
     *
     * @throws MimeTypeIsNotAllowedException
     * @throws FileIsTooLargeException
     */
    protected function existsDisk(File $file): bool
    {
        $urlWithoutProtocol = Url::splitByProtocol($file->getUrl())[1] ?? null;
        if ($urlWithoutProtocol === null || $urlWithoutProtocol === '') {
            // An empty path would probe the disk root directory itself.
            return false;
        }

        $disk = $this->getDisk($file);
        $exists = $disk->exists($urlWithoutProtocol);

        if (! $exists) {
            return false;
        }

        if (! empty($this->config['mime_types'])) {
            MimeGuard::ensureAllowed($disk->mimeType($urlWithoutProtocol), $this->config['mime_types']);
        }

        $maxBytes = $this->config['max_file_size'];

        if ($maxBytes >= 0) {
            $size = $disk->size($urlWithoutProtocol);
            if ($size > $maxBytes) {
                throw FileIsTooLargeException::create($maxBytes);
            }
        }

        return true;
    }

    /**
     * Delete a cached entry if it is not in use, recording eviction metrics
     * and dispatching CacheFileEvicted on success.
     *
     * @param  (callable(array<string, mixed>): bool)|null  $verify  See unlinkLocked().
     */
    protected function deleteEntry(string $path, string $evictionReason = 'pruned', ?callable $verify = null): DeleteResult
    {
        $result = $this->unlinkLocked($path, $verify);

        if ($result === DeleteResult::Deleted) {
            $this->metrics->evictions++;
            $this->dispatchEvent(new CacheFileEvicted($path, $evictionReason));
        }

        return $result;
    }

    /**
     * Unlink a path while holding a non-blocking exclusive flock on it.
     *
     * The flock is held on the opened inode while the deletion happens by
     * path, so before unlinking the path is compared (dev/ino) against the
     * locked stream. A mismatch means the entry was concurrently replaced
     * with a new file — deleting it would remove someone else's data.
     *
     * No events or metrics here: infrastructure cleanup (temp files,
     * claims) goes through this method directly, entry deletions go
     * through deleteEntry().
     *
     * @param  (callable(array<string, mixed>): bool)|null  $verify  Deletion guard run under the exclusive
     *                                                               lock with the fstat() of the locked inode; returning false skips the deletion.
     */
    protected function unlinkLocked(string $path, ?callable $verify = null): DeleteResult
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            clearstatcache(true, $path);

            return file_exists($path) ? DeleteResult::Skipped : DeleteResult::Gone;
        }

        try {
            if (! flock($stream, LOCK_EX | LOCK_NB)) {
                return DeleteResult::Skipped;
            }

            /** @var array<string, mixed>|false $streamStat */
            $streamStat = fstat($stream);
            if (! is_array($streamStat)) {
                return DeleteResult::Skipped;
            }

            if ($verify !== null && ! $verify($streamStat)) {
                return DeleteResult::Skipped;
            }

            clearstatcache(true, $path);
            /** @var array<string, mixed>|false $pathStat */
            $pathStat = @stat($path);
            if (! is_array($pathStat)) {
                return DeleteResult::Gone;
            }

            if ($streamStat['dev'] !== $pathStat['dev'] || $streamStat['ino'] !== $pathStat['ino']) {
                return DeleteResult::Skipped;
            }

            if (! $this->files->delete($path)) {
                return DeleteResult::Skipped;
            }

            return DeleteResult::Deleted;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to delete cached file', [
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);

            return DeleteResult::Skipped;
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    /**
     * Cache a remote or cloud storage file if it is not cached and get the path to
     * the cached file. If the file is local, nothing will be done and the path to the
     * local file will be returned.
     *
     * @return RetrievedFile Containing the 'path' to the file and the file 'stream'. Close the stream when finished.
     *
     * @throws GuzzleException
     * @throws FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     * @throws FileLockedException
     * @throws FailedToRetrieveFileException
     */
    protected function retrieve(File $file, bool $throwOnLock = false): array
    {
        try {
            $this->ensurePathExists();
            $cachedPath = $this->getCachedPath($file);
            $attempt = 0;

            while ($attempt < $this->config['lock_max_attempts']) {
                $attempt++;

                // Fast path: the entry is already published.
                $existing = $this->tryReadExisting($file, $cachedPath, $throwOnLock);
                if ($existing !== null) {
                    return $existing;
                }

                // Slow path: become the downloader (or wait for the one that is).
                $created = $this->claimAndCreate($file, $cachedPath, $throwOnLock);
                if ($created !== null) {
                    return $created;
                }
            }

            throw FailedToRetrieveFileException::create("Failed to retrieve file after {$this->config['lock_max_attempts']} attempts");
        } catch (FileLockedException $exception) {
            // Expected contention signal under throwOnLock, not an error.
            throw $exception;
        } catch (\Throwable $exception) {
            $this->metrics->errors++;

            throw $exception;
        }
    }

    /**
     * Read and validate a file that already exists in cache.
     *
     * Published entries are only ever replaced atomically (rename) or deleted
     * under an exclusive lock, so a shared lock plus an nlink check is enough
     * to guarantee a complete file. Zero-byte entries are valid content:
     * fsync-before-publish rules out power-loss zeroes.
     *
     * @return RetrievedFile|null
     */
    protected function tryReadExisting(File $file, string $cachedPath, bool $throwOnLock): ?array
    {
        $cachedFileStream = @fopen($cachedPath, 'rb');

        if ($cachedFileStream === false) {
            return null;
        }

        $closeStream = true;

        try {
            if (! $this->acquireSharedReadLock($cachedFileStream, $throwOnLock)) {
                return null;
            }

            /** @var array<string, mixed>|false $stat */
            $stat = fstat($cachedFileStream);
            if (! is_array($stat)) {
                return null;
            }

            if ($stat['nlink'] === 0) {
                // Deleted while we were opening it; retry.
                return null;
            }

            if (! $this->touchEntry($cachedPath, $cachedFileStream, $stat)) {
                // Deleted behind our back (not through the lock protocol);
                // retry.
                return null;
            }

            $this->metrics->hits++;
            $this->dispatchEvent(new CacheHit($file, $cachedPath));
            $closeStream = false;

            return [
                'path' => $cachedPath,
                'stream' => $cachedFileStream,
            ];
        } finally {
            if ($closeStream && is_resource($cachedFileStream)) {
                fclose($cachedFileStream);
            }
        }
    }

    /**
     * Acquire a shared lock on cached file stream.
     *
     * @param  resource  $cachedFileStream
     *
     * @throws FileLockedException
     */
    protected function acquireSharedReadLock($cachedFileStream, bool $throwOnLock): bool
    {
        if ($throwOnLock) {
            if (! flock($cachedFileStream, LOCK_SH | LOCK_NB)) {
                throw FileLockedException::create();
            }

            return true;
        }

        return LockManager::flockWithTimeout($cachedFileStream, LOCK_SH, $this->config['lock_wait_timeout']);
    }

    /**
     * Acquire the download claim for the entry, then either read the file a
     * competing worker published while we waited, or download it ourselves.
     *
     * @return RetrievedFile|null Null when the claim could not be acquired in
     *                            time or the published entry vanished — the caller retries.
     */
    protected function claimAndCreate(File $file, string $cachedPath, bool $throwOnLock): ?array
    {
        $claimStream = $this->openClaimStream($this->getClaimPath($cachedPath), $throwOnLock);
        if ($claimStream === null) {
            return null;
        }

        try {
            // The claim winner may have published while we waited for it.
            $existing = $this->tryReadExisting($file, $cachedPath, $throwOnLock);
            if ($existing !== null) {
                return $existing;
            }

            return $this->downloadAndPublish($file, $cachedPath);
        } finally {
            flock($claimStream, LOCK_UN);
            fclose($claimStream);
        }
    }

    /**
     * Path of the download-deduplication claim file for a cache entry.
     *
     * Claim files live in a dot-directory, so prune/clear never treat them as
     * cache entries; prune garbage-collects idle ones separately.
     */
    protected function getClaimPath(string $cachedPath): string
    {
        return "{$this->config['path']}/.locks/".basename($cachedPath).'.lock';
    }

    /**
     * Open and exclusively lock the claim file for an entry.
     *
     * After acquiring the lock the claim's nlink is rechecked: prune may have
     * garbage-collected the file while we waited, in which case our lock
     * guards a dead inode and a competing worker may own the new one.
     *
     * @return resource|null Null when the claim could not be acquired in time.
     *
     * @throws FileLockedException When $throwOnLock is set and the claim is taken.
     * @throws RuntimeException When the claim file cannot be opened.
     */
    protected function openClaimStream(string $claimPath, bool $throwOnLock)
    {
        // One deadline across all attempts: a claim GC'd under us must not
        // grant each retry a fresh lock_wait_timeout budget.
        $timeout = $this->config['lock_wait_timeout'];
        $deadline = $timeout >= 0 ? microtime(true) + $timeout : null;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $claimStream = LockManager::openLockFile($claimPath);

            if ($throwOnLock) {
                if (! flock($claimStream, LOCK_EX | LOCK_NB)) {
                    fclose($claimStream);
                    throw FileLockedException::create();
                }
            } else {
                $remaining = $deadline !== null ? max(0.0, $deadline - microtime(true)) : -1.0;
                if (! LockManager::flockWithTimeout($claimStream, LOCK_EX, $remaining)) {
                    fclose($claimStream);

                    return null;
                }
            }

            /** @var array<string, mixed>|false $stat */
            $stat = fstat($claimStream);
            if (is_array($stat) && $stat['nlink'] === 0) {
                flock($claimStream, LOCK_UN);
                fclose($claimStream);

                continue;
            }

            return $claimStream;
        }

        return null;
    }

    /**
     * Download the file into a private temp file and publish it atomically
     * under the cache path. Must be called while holding the entry's claim.
     *
     * The temp file is exclusively locked for its whole lifetime, so prune
     * cannot remove it mid-download; the lock follows the inode through the
     * rename and is converted to a shared lock for the returned stream.
     *
     * @return RetrievedFile
     *
     * @throws GuzzleException
     * @throws FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     * @throws FailedToRetrieveFileException
     */
    protected function downloadAndPublish(File $file, string $cachedPath): array
    {
        $source = Url::isRemote($file->getUrl()) ? 'remote' : 'disk';
        $this->metrics->misses++;
        $this->dispatchEvent(new CacheMiss($file));

        // Two attempts guard against the non-atomic EX→SH conversion window:
        // prune may unlink the freshly renamed entry between our conversion
        // steps, in which case the download is repeated under the same claim.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $tempPath = $this->makeTempPath($cachedPath);
            $tempStream = @fopen($tempPath, 'xb+');
            if ($tempStream === false) {
                throw FailedToRetrieveFileException::create("Could not create temp file for '{$cachedPath}'.");
            }

            try {
                if (! flock($tempStream, LOCK_EX)) {
                    // An unlocked temp file is prune fodder mid-download;
                    // retry with a fresh one.
                    fclose($tempStream);
                    @unlink($tempPath);

                    continue;
                }

                if ($source === 'remote') {
                    $this->remoteFetcher->fetch($file, $tempPath);
                } else {
                    $this->fetchDiskFile($file, $tempStream);
                }

                // Flush the payload to stable storage before the rename makes
                // it visible: otherwise a power loss can leave a zero-length
                // or truncated file under the published name. The page cache
                // is per-inode, so fsync through this descriptor also covers
                // bytes written via the fetcher's own descriptor.
                if (! fsync($tempStream)) {
                    throw FailedToRetrieveFileException::create("Could not fsync temp file for '{$cachedPath}'.");
                }

                $this->verifyMimeType($tempPath);
                $this->publish($tempPath, $cachedPath);
            } catch (\Throwable $exception) {
                fclose($tempStream);
                @unlink($tempPath); // only ever our own temp file
                throw $exception;
            }

            // Convert EX→SH on the same descriptor; it follows the inode, which
            // is now the published entry. On Linux the conversion is not atomic
            // (release, then re-acquire), hence the nlink recheck below. A
            // failed conversion may have lost the lock entirely — the stream
            // is unprotected, so it must be closed and the read retried.
            if (! flock($tempStream, LOCK_SH)) {
                fclose($tempStream);

                continue;
            }

            /** @var array<string, mixed>|false $stat */
            $stat = fstat($tempStream);
            if (! is_array($stat) || $stat['nlink'] === 0) {
                fclose($tempStream);

                continue;
            }

            $this->metrics->retrievals++;
            $bytes = is_int($stat['size'] ?? null) ? $stat['size'] : 0;
            $this->dispatchEvent(new CacheFileRetrieved($file, $cachedPath, $bytes, $source));

            return [
                'path' => $cachedPath,
                'stream' => $tempStream,
            ];
        }

        throw FailedToRetrieveFileException::create("Could not publish cached file '{$cachedPath}'.");
    }

    /**
     * Generate a unique temp path next to the cache entry.
     *
     * The name matches the pattern prune uses to garbage-collect orphaned
     * temp files of crashed writers.
     */
    protected function makeTempPath(string $cachedPath): string
    {
        return $cachedPath.'.'.getmypid().'.'.bin2hex(random_bytes(8)).'.tmp';
    }

    /**
     * Verify the MIME type of the downloaded file against the whitelist.
     *
     * @throws MimeTypeIsNotAllowedException
     */
    protected function verifyMimeType(string $path): void
    {
        if (empty($this->config['mime_types'])) {
            return;
        }

        MimeGuard::ensureAllowed($this->files->mimeType($path), $this->config['mime_types']);
    }

    /**
     * Atomically publish the temp file under the cache path.
     *
     * On Windows rename() can fail with a sharing violation while a reader
     * has the destination open, so it is retried briefly. Never falls back to
     * unlink()+rename(), which would break the readers' crash guarantees.
     *
     * @throws FailedToRetrieveFileException
     */
    protected function publish(string $tempPath, string $cachedPath): void
    {
        $attempts = PHP_OS_FAMILY === 'Windows' ? 5 : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if (@rename($tempPath, $cachedPath)) {
                return;
            }

            if ($attempt < $attempts) {
                usleep(100_000);
            }
        }

        throw FailedToRetrieveFileException::create("Could not publish cached file '{$cachedPath}'.");
    }

    /**
     * Update the access time prune() uses to find unused entries (throttled
     * by touch_interval).
     *
     * touch() works by path and creates a missing file. Entries are only
     * deleted under an exclusive lock, which our shared lock excludes, but a
     * deletion outside the lock protocol (e.g. a deploy script wiping the
     * directory) between our fstat() and the touch() would leave a new,
     * empty file under the entry name — served as valid content from then
     * on. The nlink of the locked inode tells whether that happened.
     *
     * @param  resource  $stream  The entry's stream, holding a shared lock.
     * @param  array<string, mixed>  $stat  fstat() of the locked stream.
     * @return bool False when the entry vanished (the caller retries).
     */
    protected function touchEntry(string $cachedPath, $stream, array $stat): bool
    {
        $touchInterval = $this->config['touch_interval'];
        if ($touchInterval > 0 && is_int($stat['atime'] ?? null) && (time() - $stat['atime']) < $touchInterval) {
            return true;
        }

        if (! @touch($cachedPath)) {
            $this->logger->warning('Failed to update access time for cached file', [
                'path' => $cachedPath,
                'error' => error_get_last()['message'] ?? 'Unknown error',
            ]);
        }

        /** @var array<string, mixed>|false $after */
        $after = fstat($stream);
        if (! is_array($after) || $after['nlink'] !== 0) {
            return true;
        }

        // Remove the empty file touch() may have created; a verify guard,
        // because a claim holder may have published a real entry meanwhile.
        $this->unlinkLocked($cachedPath, static fn (array $s): bool => $s['size'] === 0);

        return false;
    }

    /**
     * Copy a file from a storage disk into the given target stream.
     *
     * @param  File  $file  Cloud storage file
     * @param  resource  $target  Target file resource
     *
     * @throws FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws FailedToRetrieveFileException
     */
    protected function fetchDiskFile(File $file, $target): void
    {
        $parts = Url::splitByProtocol($file->getUrl());
        if (! isset($parts[1]) || $parts[1] === '') {
            // An empty path would ask the adapter to stream the disk root.
            throw new FileNotFoundException("Invalid file URL: {$file->getUrl()}");
        }

        $path = $parts[1];
        $disk = $this->getDisk($file);

        $source = $disk->readStream($path);
        if ($source === null) {
            throw new FileNotFoundException("Could not open file stream for path: {$path}");
        }

        try {
            if (! is_resource($source)) {
                throw SourceResourceIsInvalidException::create('The source resource could not be established.');
            }

            $maxBytes = $this->config['max_file_size'];
            $this->copyStreamWithSizeLimit($source, $target, $maxBytes, $maxBytes < 0);

            // Make the copied bytes visible to path-based readers (MIME
            // check). A failed flush means part of the payload never reached
            // the file (e.g. ENOSPC) — the copy must not pass as complete.
            if (! fflush($target)) {
                throw FailedToRetrieveFileException::create("Could not flush copied data for '{$file->getUrl()}'.");
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    /**
     * Copy stream with size limit and timeout handling.
     *
     * @param  resource  $source
     * @param  resource  $target
     * @param  string  $errorContext  Additional context for error messages
     *
     * @throws SourceResourceIsInvalidException
     * @throws FileIsTooLargeException
     * @throws SourceResourceTimedOutException
     */
    protected function copyStreamWithSizeLimit($source, $target, int $maxBytes, bool $isUnlimitedSize, string $errorContext = ''): void
    {
        $readTimeout = $this->config['read_timeout'];
        if ($readTimeout >= 0) {
            $seconds = (int) floor($readTimeout);
            $microseconds = (int) round(($readTimeout - $seconds) * 1_000_000);

            if ($microseconds === 1_000_000) {
                $seconds++;
                $microseconds = 0;
            }

            stream_set_timeout($source, $seconds, $microseconds);
        }

        $limit = $isUnlimitedSize ? -1 : ($maxBytes < PHP_INT_MAX ? $maxBytes + 1 : -1);
        $bytes = stream_copy_to_stream($source, $target, $limit);

        if ($bytes === false) {
            /** @var array<string, mixed> $metadata */
            $metadata = stream_get_meta_data($source);
            if (($metadata['timed_out'] ?? false) === true) {
                throw SourceResourceTimedOutException::create();
            }
            $message = 'Failed to copy stream data';
            if ($errorContext) {
                $message .= " {$errorContext}";
            }
            throw SourceResourceIsInvalidException::create($message);
        }

        if (! $isUnlimitedSize && $bytes > $maxBytes) {
            throw FileIsTooLargeException::create($maxBytes);
        }

        /** @var array<string, mixed> $metadata */
        $metadata = stream_get_meta_data($source);
        if (($metadata['timed_out'] ?? false) === true) {
            throw SourceResourceTimedOutException::create();
        }

        // stream_copy_to_stream returns the byte count copied so far even
        // when the source dies mid-transfer; only reaching EOF (or the copy
        // limit) proves the copy is complete. The EOF flag alone is not
        // enough: the mmap fast path for local sources never sets it, so an
        // unset flag is confirmed with a probe read (non-empty = the copy
        // stopped while data was still available).
        if (($limit < 0 || $bytes < $limit) && ! feof($source)) {
            $probe = fread($source, 1);
            if ($probe === false || $probe !== '') {
                $message = 'Source stream ended before EOF';
                if ($errorContext) {
                    $message .= " {$errorContext}";
                }
                throw SourceResourceIsInvalidException::create($message);
            }
        }
    }

    /**
     * Get the path to the cached file.
     */
    protected function getCachedPath(File $file): string
    {
        return "{$this->config['path']}/".hash('sha256', $file->getUrl());
    }

    /**
     * Get the storage disk on which a file is stored.
     *
     * @throws DiskNotAllowedException
     */
    protected function getDisk(File $file): FilesystemAdapter
    {
        $parts = Url::splitByProtocol($file->getUrl());
        $diskName = $parts[0];

        $allowedDisks = $this->config['allowed_disks'];
        if ($allowedDisks !== null && ! in_array($diskName, $allowedDisks, true)) {
            throw DiskNotAllowedException::create($diskName);
        }
        /** @var FilesystemAdapter $disk */
        $disk = $this->storage()->disk($diskName);

        return $disk;
    }

    /**
     * Creates the cache directory if it doesn't exist yet.
     */
    protected function ensurePathExists(): void
    {
        if (! $this->files->exists($this->config['path'])) {
            $this->files->makeDirectory($this->config['path'], 0777, true, true);
        }
    }
}
