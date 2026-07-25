<?php

namespace Jackardios\FileStash\Contracts;

use GuzzleHttp\Exception\GuzzleException;
use Jackardios\FileStash\Exceptions\FileIsTooLargeException;
use Jackardios\FileStash\Exceptions\HostNotAllowedException;
use Jackardios\FileStash\Exceptions\LifecycleLockTimeoutException;
use Jackardios\FileStash\Exceptions\MimeTypeIsNotAllowedException;
use Jackardios\FileStash\Support\CacheMetrics;

interface FileStash
{
    /**
     * Perform a callback with the path of a cached file. This takes care of shared
     * locks on the cached file files, so it is not corrupted due to concurrent write
     * operations.
     *
     * @param  (callable(File, string): mixed)|null  $callback  Gets the file object and the path to the cached file
     *                                                          file as arguments.
     * @param  bool  $throwOnLock  Whether to throw an exception if a file is currently locked (i.e. written to). Otherwise the method will wait until the lock is released.
     * @return mixed Result of the callback.
     *
     * @throws LifecycleLockTimeoutException When the lifecycle lock cannot be acquired in time.
     * @throws \RuntimeException
     */
    public function get(File $file, ?callable $callback = null, bool $throwOnLock = false);

    /**
     * Like `get` but deletes the cached file afterwards (if it is not used somewhere
     * else).
     *
     * The cache entry is SHARED between all callers: getOnce() evicts the
     * same entry a concurrent (or future) get() for the same URL would use,
     * so it can throw away a warmed-up cache. Prefer get() + forget() when
     * eviction should be conditional.
     *
     * @param  (callable(File, string): mixed)|null  $callback  Gets the file object and the path to the cached file
     *                                                          file as arguments.
     * @param  bool  $throwOnLock  Whether to throw an exception if a file is currently locked (i.e. written to). Otherwise the method will wait until the lock is released.
     * @return mixed Result of the callback.
     *
     * @throws LifecycleLockTimeoutException When the lifecycle lock cannot be acquired in time.
     * @throws \RuntimeException
     */
    public function getOnce(File $file, ?callable $callback = null, bool $throwOnLock = false);

    /**
     * Perform a callback with the paths of many cached files. Use this to prevent
     * pruning of the files while they are processed.
     *
     * @param  File[]  $files
     * @param  (callable(File[], string[]): mixed)|null  $callback  Gets the array of file objects and the array of paths
     *                                                              to the cached file files (in the same ordering) as arguments.
     * @param  bool  $throwOnLock  Whether to throw an exception if a file is currently locked (i.e. written to). Otherwise the method will wait until the lock is released.
     * @return mixed Result of the callback.
     *
     * @throws LifecycleLockTimeoutException When the lifecycle lock cannot be acquired in time.
     * @throws \RuntimeException
     */
    public function batch(array $files, ?callable $callback = null, bool $throwOnLock = false);

    /**
     * Like `batch` but deletes the cached files afterwards (if they are not used
     * somewhere else).
     *
     * The cache entries are SHARED between all callers: batchOnce() evicts
     * the same entries a concurrent (or future) get()/batch() for the same
     * URLs would use, so it can throw away a warmed-up cache.
     *
     * @param  File[]  $files
     * @param  (callable(File[], string[]): mixed)|null  $callback  Gets the array of file objects and the array of paths
     *                                                              to the cached file files (in the same ordering) as arguments.
     * @param  bool  $throwOnLock  Whether to throw an exception if a file is currently locked (i.e. written to). Otherwise the method will wait until the lock is released.
     * @return mixed Result of the callback.
     *
     * @throws LifecycleLockTimeoutException When the lifecycle lock cannot be acquired in time.
     * @throws \RuntimeException
     */
    public function batchOnce(array $files, ?callable $callback = null, bool $throwOnLock = false);

    /**
     * Remove cached files that are too old or exceed the maximum cache size.
     *
     *
     * @return array{deleted: int, remaining: int, total_size: int, completed: bool} Statistics about pruning operation
     *
     * @throws LifecycleLockTimeoutException When the lifecycle lock cannot be acquired in time.
     * @throws \RuntimeException
     */
    public function prune(): array;

    /**
     * Delete all unused cached files.
     *
     * @throws LifecycleLockTimeoutException When the lifecycle lock cannot be acquired in time.
     * @throws \RuntimeException
     */
    public function clear(): void;

    /**
     * Remove a specific file from the cache.
     *
     * Inside a batch()/batchOnce() callback the deletion is deferred until
     * the batch releases its shared lifecycle lock; `true` then means
     * "scheduled for deletion".
     *
     * @return bool True when the file was deleted or scheduled for deletion;
     *              false when it didn't exist, is in use, or the lifecycle
     *              lock timed out.
     */
    public function forget(File $file): bool;

    /**
     * Check if the file exists in its SOURCE (remote server or storage disk)
     * — not whether it is currently cached.
     *
     * Remote files are checked with an HTTP HEAD request; disk files via the
     * storage disk. The check also fails (throws) when the source file would
     * be rejected by the MIME type whitelist or the size limit.
     *
     *
     *
     * @return bool Whether the file exists in its source.
     *
     * @throws GuzzleException
     * @throws FileIsTooLargeException
     * @throws MimeTypeIsNotAllowedException
     * @throws HostNotAllowedException
     * @throws \InvalidArgumentException When the URL points to a storage disk that is not configured.
     * @throws \RuntimeException
     */
    public function exists(File $file): bool;

    /**
     * Get the in-process cache metrics (hits, misses, retrievals, evictions, errors).
     */
    public function metrics(): CacheMetrics;
}
