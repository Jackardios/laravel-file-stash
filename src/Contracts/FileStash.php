<?php

namespace Jackardios\FileStash\Contracts;

interface FileStash
{
    /**
     * Perform a callback with the path of a cached file. This takes care of shared
     * locks on the cached file files, so it is not corrupted due to concurrent write
     * operations.
     *
     * @param \Jackardios\FileStash\Contracts\File $file
     * @param (callable(\Jackardios\FileStash\Contracts\File, string): mixed)|null $callback Gets the file object and the path to the cached file
     * file as arguments.
     * @param bool $throwOnLock Whether to throw an exception if a file is currently locked (i.e. written to). Otherwise the method will wait until the lock is released.
     *
     * @throws \RuntimeException
     *
     * @return mixed Result of the callback.
     */
    public function get(File $file, ?callable $callback = null, bool $throwOnLock = false);

    /**
     * Like `get` but deletes the cached file afterwards (if it is not used somewhere
     * else).
     *
     * @param \Jackardios\FileStash\Contracts\File $file
     * @param (callable(\Jackardios\FileStash\Contracts\File, string): mixed)|null $callback Gets the file object and the path to the cached file
     * file as arguments.
     * @param bool $throwOnLock Whether to throw an exception if a file is currently locked (i.e. written to). Otherwise the method will wait until the lock is released.
     *
     * @throws \RuntimeException
     *
     * @return mixed Result of the callback.
     */
    public function getOnce(File $file, ?callable $callback = null, bool $throwOnLock = false);

    /**
     * Perform a callback with the paths of many cached files. Use this to prevent
     * pruning of the files while they are processed.
     *
     * @param \Jackardios\FileStash\Contracts\File[] $files
     * @param (callable(\Jackardios\FileStash\Contracts\File[], string[]): mixed)|null $callback Gets the array of file objects and the array of paths
     * to the cached file files (in the same ordering) as arguments.
     * @param bool $throwOnLock Whether to throw an exception if a file is currently locked (i.e. written to). Otherwise the method will wait until the lock is released.
     *
     * @throws \RuntimeException
     *
     * @return mixed Result of the callback.
     */
    public function batch(array $files, ?callable $callback = null, bool $throwOnLock = false);

    /**
     * Like `batch` but deletes the cached files afterwards (if they are not used
     * somewhere else).
     *
     * @param \Jackardios\FileStash\Contracts\File[] $files
     * @param (callable(\Jackardios\FileStash\Contracts\File[], string[]): mixed)|null $callback Gets the array of file objects and the array of paths
     * to the cached file files (in the same ordering) as arguments.
     * @param bool $throwOnLock Whether to throw an exception if a file is currently locked (i.e. written to). Otherwise the method will wait until the lock is released.
     *
     * @throws \RuntimeException
     *
     * @return mixed Result of the callback.
     */
    public function batchOnce(array $files, ?callable $callback = null, bool $throwOnLock = false);

    /**
     * Remove cached files that are too old or exceed the maximum cache size.
     *
     * @throws \RuntimeException
     *
     * @return array{deleted: int, remaining: int, total_size: int, completed: bool} Statistics about pruning operation
     */
    public function prune(): array;

    /**
     * Delete all unused cached files.
     *
     * @throws \RuntimeException
     */
    public function clear(): void;

    /**
     * Remove a specific file from the cache.
     *
     * @param \Jackardios\FileStash\Contracts\File $file
     *
     * @return bool True if the file was deleted, false if it didn't exist or is locked.
     */
    public function forget(File $file): bool;

    /**
     * Check if a file exists.
     *
     * @param \Jackardios\FileStash\Contracts\File $file
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Jackardios\FileStash\Exceptions\FileIsTooLargeException
     * @throws \Jackardios\FileStash\Exceptions\MimeTypeIsNotAllowedException
     * @throws \Jackardios\FileStash\Exceptions\HostNotAllowedException
     * @throws \RuntimeException
     *
     * @return bool Whether the file exists or not.
     */
    public function exists(File $file): bool;
}
