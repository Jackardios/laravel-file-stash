<?php

namespace Jackardios\FileStash;

use GuzzleHttp\Exception\RequestException;
use Jackardios\FileStash\Contracts\File;
use Jackardios\FileStash\Contracts\FileStash as FileStashContract;
use Jackardios\FileStash\Exceptions\FailedToRetrieveFileException;
use Jackardios\FileStash\Exceptions\FileIsTooLargeException;
use Jackardios\FileStash\Exceptions\FileLockedException;
use Jackardios\FileStash\Exceptions\HostNotAllowedException;
use Jackardios\FileStash\Exceptions\MimeTypeIsNotAllowedException;
use Jackardios\FileStash\Exceptions\SourceResourceIsInvalidException;
use Jackardios\FileStash\Exceptions\SourceResourceTimedOutException;
use Jackardios\FileStash\Events\CacheFileEvicted;
use Jackardios\FileStash\Events\CacheFileRetrieved;
use Jackardios\FileStash\Events\CacheHit;
use Jackardios\FileStash\Events\CacheMiss;
use Jackardios\FileStash\Events\CachePruneCompleted;
use Jackardios\FileStash\Support\CacheMetrics;
use Jackardios\FileStash\Support\ConfigNormalizer;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * The file cache.
 *
 * @phpstan-import-type NormalizedConfig from ConfigNormalizer
 * @phpstan-type RetrievedFile array{path: string, stream: resource}
 */
class FileStash implements FileStashContract
{
    /**
     * @var NormalizedConfig
     */
    protected array $config;

    /**
     * HTTP client used for remote file operations.
     */
    protected Client $client;

    /**
     * Filesystem helper.
     */
    protected Filesystem $files;

    /**
     * Filesystem manager for storage disks.
     */
    protected FilesystemManager $storage;

    /**
     * Logger instance for diagnostic logging.
     */
    protected LoggerInterface $logger;

    /**
     * Event dispatcher for cache events.
     */
    protected ?Dispatcher $dispatcher;

    /**
     * In-process cache metrics.
     */
    protected CacheMetrics $metrics;

    /**
     * In-memory cache of URL→path mappings.
     *
     * @var array<string, string>
     */
    protected array $pathCache = [];

    /**
     * Create an instance.
     *
     * @param array<string, mixed> $config
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
        $this->client = $client ?? $this->makeHttpClient();
        $this->files = $files ?? $this->resolveFilesystem();
        $this->storage = $storage ?? $this->resolveFilesystemManager();
        $this->logger = $logger ?: new NullLogger();
        $this->dispatcher = $this->config['events_enabled']
            ? ($dispatcher ?? $this->resolveEventDispatcher())
            : null;
        $this->metrics = new CacheMetrics();
    }

    protected function resolveFilesystem(): Filesystem
    {
        $files = app('files');
        if (!$files instanceof Filesystem) {
            throw new RuntimeException('The "files" service must resolve to Illuminate\\Filesystem\\Filesystem.');
        }

        return $files;
    }

    protected function resolveFilesystemManager(): FilesystemManager
    {
        $filesystem = app('filesystem');
        if (!$filesystem instanceof FilesystemManager) {
            throw new RuntimeException('The "filesystem" service must resolve to Illuminate\\Filesystem\\FilesystemManager.');
        }

        return $filesystem;
    }

    protected function resolveEventDispatcher(): ?Dispatcher
    {
        try {
            $dispatcher = app(Dispatcher::class);
            if (!$dispatcher instanceof Dispatcher) {
                return null;
            }
            return $dispatcher;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get the in-process cache metrics.
     */
    public function metrics(): CacheMetrics
    {
        return $this->metrics;
    }

    /**
     * Remove a specific file from the cache.
     *
     * @param File $file The file to remove from cache
     * @return bool True if the file was deleted, false if it didn't exist or is locked
     */
    public function forget(File $file): bool
    {
        $cachedPath = $this->getCachedPath($file);

        if (!$this->files->exists($cachedPath)) {
            return false;
        }

        return $this->delete(new SplFileInfo($cachedPath));
    }

    /**
     * {@inheritdoc}
     *
     * @throws GuzzleException
     * @throws MimeTypeIsNotAllowedException
     * @throws FileIsTooLargeException
     * @throws HostNotAllowedException
     */
    public function exists(File $file): bool
    {
        return $this->isRemote($file) ? $this->existsRemote($file) : $this->existsDisk($file);
    }

    /**
     * {@inheritdoc}
     * @throws GuzzleException
     * @throws FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     * @throws FileLockedException
     * @throws FailedToRetrieveFileException
     */
    public function get(File $file, ?callable $callback = null, bool $throwOnLock = false)
    {
        $callback = $callback ?? \Closure::fromCallable([static::class, 'defaultGetCallback']);

        return $this->batch([$file], function ($files, $paths) use ($callback) {
            return $callback($files[0], $paths[0]);
        }, $throwOnLock);
    }

    /**
     * {@inheritdoc}
     * @throws GuzzleException
     * @throws FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     * @throws FileLockedException
     * @throws FailedToRetrieveFileException
     */
    public function getOnce(File $file, ?callable $callback = null, bool $throwOnLock = false)
    {
        $callback = $callback ?? \Closure::fromCallable([static::class, 'defaultGetCallback']);

        return $this->batchOnce([$file], function ($files, $paths) use ($callback) {
            return $callback($files[0], $paths[0]);
        }, $throwOnLock);
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int, File> $files
     * @throws GuzzleException
     * @throws FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     * @throws FileLockedException
     * @throws FailedToRetrieveFileException
     */
    public function batch(array $files, ?callable $callback = null, bool $throwOnLock = false)
    {
        $callback = $callback ?? \Closure::fromCallable([static::class, 'defaultBatchCallback']);

        return $this->runBatchRetrieval($files, $callback, $throwOnLock);
    }

    /**
     * Retrieve all files for batch-like operations under a shared lifecycle lock.
     *
     * @param array<int, File> $files
     * @param callable(array<int, File>, array<int, string>): mixed $callback
     * @param array<int, string> $processedPaths
     * @return mixed
     */
    protected function runBatchRetrieval(
        array $files,
        callable $callback,
        bool $throwOnLock,
        array &$processedPaths = []
    ) {
        return $this->withLifecycleSharedLock(function () use ($files, $callback, $throwOnLock, &$processedPaths) {
            return $this->runBatchByChunkStrategy($files, $callback, $throwOnLock, $processedPaths);
        });
    }

    /**
     * Execute batch processing using either direct or chunked strategy.
     *
     * @param array<int, File> $files
     * @param callable(array<int, File>, array<int, string>): mixed $callback
     * @param array<int, string> $processedPaths
     * @return mixed
     */
    protected function runBatchByChunkStrategy(
        array $files,
        callable $callback,
        bool $throwOnLock,
        array &$processedPaths = []
    ) {
        $chunkSize = $this->config['batch_chunk_size'];

        if ($chunkSize < 0 || count($files) <= $chunkSize) {
            return $this->processBatch($files, $callback, $throwOnLock, $processedPaths);
        }

        /** @var int<1, max> $chunkSize */
        return $this->processBatchChunked($files, $callback, $throwOnLock, $chunkSize, $processedPaths);
    }

    /**
     * Process files in chunks to limit concurrently opened cached file streams.
     *
     * @param array<int, File> $files
     * @param callable(array<int, File>, array<int, string>): mixed $callback
     * @param array<int, string> $processedPaths
     * @param int<1, max> $chunkSize
     * @return mixed
     */
    protected function processBatchChunked(
        array $files,
        callable $callback,
        bool $throwOnLock,
        int $chunkSize,
        array &$processedPaths = []
    )
    {
        /** @var array<int, string> $allPaths */
        $allPaths = [];
        /** @var array<int, array<int, File>> $chunks */
        $chunks = array_chunk($files, $chunkSize, true);

        foreach ($chunks as $chunkFiles) {
            /** @var array<int, RetrievedFile> $chunkRetrieved */
            $chunkRetrieved = [];

            try {
                foreach ($chunkFiles as $index => $file) {
                    $chunkRetrieved[$index] = $this->retrieve($file, $throwOnLock);
                    $allPaths[$index] = $chunkRetrieved[$index]['path'];
                    $processedPaths[$index] = $chunkRetrieved[$index]['path'];
                }
            } finally {
                foreach ($chunkRetrieved as $retrievedFile) {
                    if (is_resource($retrievedFile['stream'])) {
                        fclose($retrievedFile['stream']);
                    }
                }
            }
        }

        /** @var array<int, string> $paths */
        $paths = [];
        foreach ($files as $index => $_file) {
            if (isset($allPaths[$index])) {
                $paths[$index] = $allPaths[$index];
            }
        }

        return $callback($files, $paths);
    }

    /**
     * Process a batch of files (internal implementation).
     *
     * @param array<int, File> $files
     * @param callable(array<int, File>, array<int, string>): mixed $callback
     * @param array<int, string> $processedPaths Filled with cached paths that were successfully retrieved
     * @return mixed
     */
    protected function processBatch(array $files, callable $callback, bool $throwOnLock, array &$processedPaths = [])
    {
        /** @var array<int, RetrievedFile> $retrieved */
        $retrieved = [];
        try {
            foreach ($files as $index => $file) {
                $retrieved[$index] = $this->retrieve($file, $throwOnLock);
                $processedPaths[$index] = $retrieved[$index]['path'];
            }

            /** @var array<int, string> $paths */
            $paths = array_map(static fn(array $file): string => $file['path'], $retrieved);

            return $callback($files, $paths);
        } finally {
            foreach ($retrieved as $file) {
                if (!is_resource($file['stream'])) {
                    continue;
                }
                fclose($file['stream']);
            }
        }
    }

    /**
     * {@inheritdoc}
     * @throws GuzzleException
     * @throws FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     * @throws FileLockedException
     * @throws FailedToRetrieveFileException
     */
    public function batchOnce(array $files, ?callable $callback = null, bool $throwOnLock = false)
    {
        $callback = $callback ?? \Closure::fromCallable([static::class, 'defaultBatchCallback']);
        /** @var array<int, string> $processedPaths */
        $processedPaths = [];
        $result = null;
        $capturedException = null;
        $cleanupException = null;

        try {
            $result = $this->runBatchRetrieval($files, $callback, $throwOnLock, $processedPaths);
        } catch (\Throwable $exception) {
            $capturedException = $exception;
        }

        $pathsToDelete = array_values(array_unique(array_values($processedPaths)));
        if (!empty($pathsToDelete)) {
            try {
                $this->withLifecycleExclusiveLock(function () use ($pathsToDelete) {
                    $this->deleteCachedPaths($pathsToDelete);
                });
            } catch (\Throwable $exception) {
                $cleanupException = $exception;
            }
        }

        if ($capturedException !== null) {
            if ($cleanupException !== null) {
                $this->logger->warning('Failed to clean cached files after batchOnce callback exception.', [
                    'paths_count' => count($pathsToDelete),
                    'exception' => $cleanupException->getMessage(),
                ]);
            }
            throw $capturedException;
        }

        if ($cleanupException !== null) {
            throw $cleanupException;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * @return array{deleted: int, remaining: int, total_size: int, completed: bool} Statistics about pruning operation
     */
    public function prune(): array
    {
        /** @var array{deleted: int, remaining: int, total_size: int, completed: bool} $stats */
        $stats = $this->withLifecycleSharedLock(function (): array {
            $stats = ['deleted' => 0, 'remaining' => 0, 'total_size' => 0, 'completed' => true];

            if (!$this->files->exists($this->config['path'])) {
                return $stats;
            }

            $startTime = time();
            $timeout = $this->config['prune_timeout'];
            $now = time();
            $allowedAge = $this->config['max_age'] * 60;
            $allowedSize = $this->config['max_size'];

            $fileInfos = [];
            $files = Finder::create()
                ->files()
                ->ignoreDotFiles(true)
                ->in($this->config['path'])
                ->getIterator();

            foreach ($files as $file) {
                if ($this->isPruneTimedOut($startTime, $timeout, 'file collection')) {
                    $this->logger->warning('Prune operation timed out during file collection');
                    $stats['completed'] = false;
                    return $stats;
                }

                try {
                    $fileInfos[] = [
                        'file' => $file,
                        'atime' => $file->getATime(),
                        'size' => $file->getSize(),
                    ];
                } catch (RuntimeException $e) {
                    continue;
                }
            }

            usort($fileInfos, static fn($a, $b) => $a['atime'] <=> $b['atime']);

            $totalSize = 0;
            $remainingCount = 0;
            $remainingFiles = [];

            foreach ($fileInfos as $info) {
                if ($this->isPruneTimedOut($startTime, $timeout, 'age-based pruning')) {
                    $stats['completed'] = false;
                    return $stats;
                }

                $isExpired = ($now - $info['atime']) > $allowedAge;

                if ($isExpired && $this->delete($info['file'], 'pruned_age')) {
                    $stats['deleted']++;
                    continue;
                }

                $totalSize += $info['size'];
                $remainingCount++;
                $remainingFiles[] = $info;
            }

            if ($totalSize > $allowedSize) {
                foreach ($remainingFiles as $info) {
                    if ($totalSize <= $allowedSize) {
                        break;
                    }

                    if ($this->isPruneTimedOut($startTime, $timeout, 'size-based pruning', $totalSize - $allowedSize)) {
                        $stats['completed'] = false;
                        break;
                    }

                    if ($this->delete($info['file'], 'pruned_size')) {
                        $totalSize -= $info['size'];
                        $stats['deleted']++;
                        $remainingCount--;
                    }
                }
            }

            $stats['total_size'] = $totalSize;
            $stats['remaining'] = $remainingCount;

            return $stats;
        });

        if ($this->dispatcher !== null) {
            $this->dispatcher->dispatch(new CachePruneCompleted(
                $stats['deleted'],
                $stats['remaining'],
                $stats['total_size'],
                $stats['completed']
            ));
        }

        return $stats;
    }

    /**
     * Check if prune operation has timed out.
     *
     * @param int $startTime Start time of the prune operation
     * @param int $timeout Timeout in seconds (0 or negative = no timeout)
     * @param string $phase Current pruning phase for logging
     * @param int|null $remainingSize Remaining size to prune (for logging)
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
     */
    public function clear(): void
    {
        $this->withLifecycleExclusiveLock(function () {
            if (!$this->files->exists($this->config['path'])) {
                return;
            }

            $files = Finder::create()
                ->files()
                ->ignoreDotFiles(true)
                ->in($this->config['path'])
                ->getIterator();

            foreach ($files as $file) {
                $this->delete($file, 'cleared');
            }
        });
    }

    /**
     * Execute callback while holding a shared lifecycle lock.
     *
     * The shared lock keeps prune/clear and one-time deletion from removing
     * files while they are in active use.
     *
     * @param callable $callback
     * @return mixed
     */
    protected function withLifecycleSharedLock(callable $callback)
    {
        return $this->withLifecycleLock(LOCK_SH, $callback);
    }

    /**
     * Execute callback while holding an exclusive lifecycle lock.
     *
     * @param callable $callback
     * @return mixed
     */
    protected function withLifecycleExclusiveLock(callable $callback)
    {
        return $this->withLifecycleLock(LOCK_EX, $callback);
    }

    /**
     * Execute callback while holding a lifecycle lock.
     *
     * @param int $lockType
     * @param callable $callback
     * @return mixed
     */
    protected function withLifecycleLock(int $lockType, callable $callback)
    {
        $lockStream = $this->openLifecycleLockStream();
        $lockTimeout = $this->config['lifecycle_lock_timeout'];
        $hasLockTimeout = $lockTimeout >= 0;
        $startTime = microtime(true);

        while (!flock($lockStream, $lockType | LOCK_NB)) {
            if ($hasLockTimeout && (microtime(true) - $startTime) >= $lockTimeout) {
                fclose($lockStream);
                throw new RuntimeException(
                    "Failed to acquire file cache lifecycle lock within {$lockTimeout} seconds."
                );
            }

            usleep(random_int(30000, 70000)); // ~50ms with jitter
        }

        try {
            return $callback();
        } finally {
            flock($lockStream, LOCK_UN);
            fclose($lockStream);
        }
    }

    /**
     * Open the lifecycle lock stream.
     *
     * @return resource
     */
    protected function openLifecycleLockStream()
    {
        $path = $this->getLifecycleLockPath();
        $directory = dirname($path);

        if (!$this->files->exists($directory)) {
            $this->files->makeDirectory($directory, 0755, true, true);
        }

        $stream = @fopen($path, 'c+');
        if ($stream === false) {
            throw new RuntimeException("Failed to open file cache lifecycle lock at '{$path}'.");
        }

        return $stream;
    }

    /**
     * Get path for the lifecycle lock file.
     */
    protected function getLifecycleLockPath(): string
    {
        $suffix = hash('sha256', $this->normalizePathForLock($this->config['path']));

        return sys_get_temp_dir() . '/laravel-file-stash/locks/' . $suffix . '.lock';
    }

    /**
     * Normalize cache path before deriving lifecycle lock key.
     */
    protected function normalizePathForLock(string $path): string
    {
        $realPath = @realpath($path);
        if ($realPath !== false) {
            return $realPath;
        }

        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            return '/';
        }

        if (preg_match('/^[A-Za-z]:$/', $normalized) === 1) {
            return $normalized . '/';
        }

        return $normalized;
    }

    /**
     * Delete cached paths if no active lock exists on those files.
     *
     * @param string[] $paths
     */
    protected function deleteCachedPaths(array $paths): void
    {
        foreach ($paths as $path) {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }

            try {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    $this->files->delete($path);
                    $this->metrics->evictions++;
                    if ($this->dispatcher !== null) {
                        $this->dispatcher->dispatch(new CacheFileEvicted($path, 'once'));
                    }
                }
            } finally {
                fclose($handle);
            }
        }
    }

    /**
     * Determine whether an HTTP status is retryable.
     */
    protected function shouldRetryHttpStatus(int $statusCode): bool
    {
        if ($statusCode === 0 || $statusCode === 429) {
            return true;
        }

        return $statusCode >= 500;
    }

    /**
     * Extract HTTP status code from a Guzzle exception.
     */
    protected function extractHttpStatusCode(GuzzleException $exception): int
    {
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $response = $exception->getResponse();
            if ($response !== null) {
                return $response->getStatusCode();
            }
        }

        return 0;
    }

    /**
     * Determine whether a failed HTTP request should be retried.
     */
    protected function shouldRetryHttpFailure(int $attempt, int $maxRetries, int $statusCode): bool
    {
        return $attempt <= $maxRetries && $this->shouldRetryHttpStatus($statusCode);
    }

    /**
     * Log and delay before next HTTP retry attempt.
     *
     * @param array<string, mixed> $context
     */
    protected function backoffHttpRetry(
        File $file,
        string $method,
        int $attempt,
        int $maxRetries,
        int $retryDelay,
        array $context = []
    ): void {
        $this->logger->warning("HTTP {$method} request failed, retrying ({$attempt}/{$maxRetries})", [
            'url' => $this->sanitizeUrlForLogging($file->getUrl()),
            ...$context,
        ]);

        $delay = (int) min($retryDelay * pow(2, $attempt - 1), 30000);
        $actual = random_int((int) ($delay * 0.5), (int) ($delay * 1.5));
        usleep($actual * 1000);
    }

    /**
     * Extract HTTP status code from a retrieval exception message.
     */
    protected function extractRetrieveFailureStatusCode(FailedToRetrieveFileException $exception): int
    {
        if (preg_match('/status code (\d+)/', $exception->getMessage(), $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Check for existence of a remote file.
     *
     * @throws MimeTypeIsNotAllowedException
     * @throws FileIsTooLargeException
     * @throws HostNotAllowedException
     */
    protected function existsRemote(File $file): bool
    {
        $this->validateHost($file->getUrl());
        $attempt = 0;
        $maxRetries = $this->config['http_retries'];
        $retryDelay = $this->config['http_retry_delay'];

        while ($attempt <= $maxRetries) {
            $attempt++;

            try {
                $response = $this->client->head($this->encodeUrl($file->getUrl()));
                $code = $response->getStatusCode();

                if ($code < 200 || $code >= 300) {
                    if ($this->shouldRetryHttpFailure($attempt, $maxRetries, $code)) {
                        $this->backoffHttpRetry($file, 'HEAD', $attempt, $maxRetries, $retryDelay, [
                            'status_code' => $code,
                        ]);
                        continue;
                    }
                    return false;
                }

                if (!empty($this->config['mime_types'])) {
                    $type = $response->getHeaderLine('content-type');
                    $type = trim(explode(';', $type)[0]);
                    if ($type && !in_array($type, $this->config['mime_types'], true)) {
                        throw MimeTypeIsNotAllowedException::create($type);
                    }
                }

                $maxBytes = $this->config['max_file_size'];
                $contentLength = $response->getHeaderLine('content-length');
                $contentBytes = is_numeric($contentLength) ? (int) $contentLength : null;

                if ($maxBytes >= 0 && $contentBytes !== null && $contentBytes > $maxBytes) {
                    throw FileIsTooLargeException::create($maxBytes);
                }

                return true;
            } catch (GuzzleException $exception) {
                $statusCode = $this->extractHttpStatusCode($exception);

                // Respect bool semantics for exists() when the client is configured
                // with http_errors=true and Guzzle throws for HTTP 4xx/5xx responses.
                if ($statusCode >= 400) {
                    if ($this->shouldRetryHttpFailure($attempt, $maxRetries, $statusCode)) {
                        $this->backoffHttpRetry($file, 'HEAD', $attempt, $maxRetries, $retryDelay, [
                            'status_code' => $statusCode,
                            'exception' => $exception->getMessage(),
                        ]);
                        continue;
                    }

                    return false;
                }

                if (!$this->shouldRetryHttpFailure($attempt, $maxRetries, $statusCode)) {
                    throw $exception;
                }

                $this->backoffHttpRetry($file, 'HEAD', $attempt, $maxRetries, $retryDelay, [
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return false;
    }

    /**
     * Check for existence of a file from a storage disk.
     *
     * @throws MimeTypeIsNotAllowedException
     * @throws FileIsTooLargeException
     */
    protected function existsDisk(File $file): bool
    {
        $urlWithoutProtocol = $this->splitByProtocol($file->getUrl())[1] ?? null;
        if ($urlWithoutProtocol === null) {
            return false;
        }

        $disk = $this->getDisk($file);
        $exists = $disk->exists($urlWithoutProtocol);

        if (!$exists) {
            return false;
        }

        if (!empty($this->config['mime_types'])) {
            $type = $disk->mimeType($urlWithoutProtocol);
            if (!is_string($type)) {
                $type = '(unknown)';
            }
            if (!in_array($type, $this->config['mime_types'], true)) {
                throw MimeTypeIsNotAllowedException::create($type);
            }
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
     * Delete a cached file if it is not used.
     *
     * @param SplFileInfo $file
     *
     * @return bool If the file has been deleted.
     */
    protected function delete(SplFileInfo $file, string $evictionReason = 'pruned'): bool
    {
        $fileStream = null;
        $deleted = false;
        $filePath = $file->getRealPath();

        if ($filePath === false) {
            return true;
        }

        try {
            $fileStream = @fopen($filePath, 'rb');
            if ($fileStream === false) {
                return !file_exists($filePath);
            }

            if (flock($fileStream, LOCK_EX | LOCK_NB)) {
                $this->files->delete($filePath);
                $deleted = true;
                $this->metrics->evictions++;
                if ($this->dispatcher !== null) {
                    $this->dispatcher->dispatch(new CacheFileEvicted($filePath, $evictionReason));
                }
            }
        } catch (\Throwable $e) {
            return false;
        } finally {
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }
        }

        return $deleted;
    }

    /**
     * Cache a remote or cloud storage file if it is not cached and get the path to
     * the cached file. If the file is local, nothing will be done and the path to the
     * local file will be returned.
     *
     * @return RetrievedFile Containing the 'path' to the file and the file 'stream'. Close the stream when finished.
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
        $this->ensurePathExists();
        $cachedPath = $this->getCachedPath($file);
        $attempt = 0;

        while ($attempt < $this->config['lock_max_attempts']) {
            $attempt++;

            $cachedFileStream = @fopen($cachedPath, 'xb+');

            if (is_resource($cachedFileStream)) {
                $newlyRetrieved = $this->retrieveByCreatingCacheFile($file, $cachedPath, $cachedFileStream);
                if ($newlyRetrieved !== null) {
                    return $newlyRetrieved;
                }
                continue;
            }

            $existingRetrieved = $this->retrieveFromExistingCache($file, $cachedPath, $throwOnLock);
            if ($existingRetrieved !== null) {
                return $existingRetrieved;
            }
        }

        $this->metrics->errors++;
        throw FailedToRetrieveFileException::create("Failed to retrieve file after {$this->config['lock_max_attempts']} attempts");
    }

    /**
     * Read and validate a file that already exists in cache.
     *
     * @return RetrievedFile|null
     */
    protected function retrieveFromExistingCache(File $file, string $cachedPath, bool $throwOnLock): ?array
    {
        $cachedFileStream = @fopen($cachedPath, 'rb');

        if ($cachedFileStream === false) {
            usleep(random_int(70000, 130000)); // ~100ms with jitter
            return null;
        }

        $closeStream = true;

        try {
            if (!$this->acquireSharedReadLock($cachedFileStream, $throwOnLock)) {
                return null;
            }

            /** @var array<string, mixed>|false $stat */
            $stat = fstat($cachedFileStream);
            if (!is_array($stat)) {
                return null;
            }

            if ($stat['nlink'] === 0) {
                return null;
            }

            if ($stat['size'] === 0) {
                fclose($cachedFileStream);
                $closeStream = false;
                $this->delete(new SplFileInfo($cachedPath));
                return null;
            }

            $closeStream = false;
            return $this->retrieveExistingFile($cachedPath, $cachedFileStream, $file, $stat);
        } finally {
            if ($closeStream && is_resource($cachedFileStream)) {
                fclose($cachedFileStream);
            }
        }
    }

    /**
     * Acquire a shared lock on cached file stream.
     *
     * @param resource $cachedFileStream
     *
     * @throws FileLockedException
     */
    protected function acquireSharedReadLock($cachedFileStream, bool $throwOnLock): bool
    {
        if ($throwOnLock) {
            if (!flock($cachedFileStream, LOCK_SH | LOCK_NB)) {
                throw FileLockedException::create();
            }

            return true;
        }

        $lockAcquired = false;
        $startTime = microtime(true);
        $lockTimeout = $this->config['lock_wait_timeout'];
        $hasLockTimeout = $lockTimeout >= 0;

        while (!$lockAcquired) {
            $lockAcquired = flock($cachedFileStream, LOCK_SH | LOCK_NB);

            if ($hasLockTimeout && (microtime(true) - $startTime) >= $lockTimeout) {
                return false;
            }

            if (!$lockAcquired) {
                usleep(random_int(30000, 70000)); // ~50ms with jitter
            }
        }

        return true;
    }

    /**
     * Retrieve a file by creating a new cache entry.
     *
     * @param resource $cachedFileStream
     *
     * @return RetrievedFile|null
     */
    protected function retrieveByCreatingCacheFile(File $file, string $cachedPath, $cachedFileStream): ?array
    {
        if (!flock($cachedFileStream, LOCK_EX | LOCK_NB)) {
            fclose($cachedFileStream);
            @unlink($cachedPath);
            return null;
        }

        try {
            $fileInfo = $this->retrieveNewFile($file, $cachedPath, $cachedFileStream);
            flock($cachedFileStream, LOCK_SH);
            return $fileInfo;
        } catch (\Throwable $exception) {
            fclose($cachedFileStream);
            @unlink($cachedPath);

            throw $exception;
        }
    }

    /**
     * Get path and stream for a file that exists in the cache.
     *
     * @param string $cachedPath
     * @param resource $cachedFileStream
     * @param File|null $file The file object, used for events/metrics
     * @param array<string, mixed>|null $stat File stat array from fstat(), used for touch throttling
     *
     * @return RetrievedFile
     */
    protected function retrieveExistingFile(string $cachedPath, $cachedFileStream, ?File $file = null, ?array $stat = null): array
    {
        $touchInterval = $this->config['touch_interval'];
        $shouldTouch = true;

        if ($touchInterval > 0 && is_array($stat) && isset($stat['atime'])) {
            $shouldTouch = (time() - $stat['atime']) >= $touchInterval;
        }

        if ($shouldTouch && !@touch($cachedPath)) {
            $this->logger->warning('Failed to update access time for cached file', [
                'path' => $cachedPath,
                'error' => error_get_last()['message'] ?? 'Unknown error',
            ]);
        }

        $this->metrics->hits++;
        if ($file !== null && $this->dispatcher !== null) {
            $this->dispatcher->dispatch(new CacheHit($file, $cachedPath));
        }

        return [
            'path' => $cachedPath,
            'stream' => $cachedFileStream,
        ];
    }

    /**
     * Get path and stream for a file that does not yet exist in the cache.
     *
     * @param File $file
     * @param string $cachedPath
     * @param resource $cachedFileStream
     *
     * @return RetrievedFile
     *
     * @throws GuzzleException
     * @throws FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     * @throws MimeTypeIsNotAllowedException
     */
    protected function retrieveNewFile(File $file, string $cachedPath, $cachedFileStream): array
    {
        $source = $this->isRemote($file) ? 'remote' : 'disk';
        $this->metrics->misses++;
        if ($this->dispatcher !== null) {
            $this->dispatcher->dispatch(new CacheMiss($file, $file->getUrl()));
        }

        if ($source === 'remote') {
            $cachedPath = $this->getRemoteFile($file, $cachedFileStream);
        } else {
            $newCachedPath = $this->getDiskFile($file, $cachedFileStream);

            if ($newCachedPath !== $cachedPath) {
                @unlink($cachedPath);
            }

            $cachedPath = $newCachedPath;
        }

        if (!empty($this->config['mime_types'])) {
            $type = $this->files->mimeType($cachedPath);
            if (!is_string($type)) {
                $type = '(unknown)';
            }
            if (!in_array($type, $this->config['mime_types'], true)) {
                throw MimeTypeIsNotAllowedException::create($type);
            }
        }

        $this->metrics->retrievals++;
        if ($this->dispatcher !== null) {
            $bytes = @filesize($cachedPath);
            $this->dispatcher->dispatch(new CacheFileRetrieved($file, $cachedPath, $bytes ?: 0, $source));
        }

        return [
            'path' => $cachedPath,
            'stream' => $cachedFileStream,
        ];
    }

    /**
     * Cache a remote file and get the path to the cached file.
     *
     * @param File $file Remote file
     * @param resource $target Target file resource
     *
     * @return string
     *
     * @throws GuzzleException
     * @throws FileIsTooLargeException
     * @throws SourceResourceTimedOutException
     * @throws SourceResourceIsInvalidException
     * @throws HostNotAllowedException
     */
    protected function getRemoteFile(File $file, $target): string
    {
        $this->validateHost($file->getUrl());

        $cachedPath = $this->getCachedPath($file);

        $maxBytes = $this->config['max_file_size'];
        $isUnlimitedSize = $maxBytes < 0;

        $attempt = 0;
        $maxRetries = $this->config['http_retries'];
        $retryDelay = $this->config['http_retry_delay'];

        $lastException = null;

        while ($attempt <= $maxRetries) {
            $attempt++;

            try {
                return $this->fetchRemoteFile($file, $target, $cachedPath, $maxBytes, $isUnlimitedSize);
            } catch (GuzzleException $exception) {
                $lastException = $exception;
                $previous = $exception->getPrevious();
                if ($previous instanceof FileIsTooLargeException) {
                    throw $previous;
                }

                $statusCode = $this->extractHttpStatusCode($exception);
                if (!$this->shouldRetryHttpFailure($attempt, $maxRetries, $statusCode)) {
                    throw $exception;
                }

                $context = ['exception' => $exception->getMessage()];
                if ($statusCode > 0) {
                    $context['status_code'] = $statusCode;
                }
                $this->backoffHttpRetry($file, 'GET', $attempt, $maxRetries, $retryDelay, $context);
                continue;
            } catch (FailedToRetrieveFileException $exception) {
                $lastException = $exception;
                $statusCode = $this->extractRetrieveFailureStatusCode($exception);

                if (!$this->shouldRetryHttpFailure($attempt, $maxRetries, $statusCode)) {
                    throw $exception;
                }

                $context = ['exception' => $exception->getMessage()];
                if ($statusCode > 0) {
                    $context['status_code'] = $statusCode;
                }
                $this->backoffHttpRetry($file, 'GET', $attempt, $maxRetries, $retryDelay, $context);
                continue;
            }
        }

        throw $lastException ?? FailedToRetrieveFileException::create('Failed to fetch remote file');
    }

    /**
     * Actually fetch the remote file content.
     *
     * @param File $file
     * @param resource $target
     * @param string $cachedPath
     * @param int $maxBytes
     * @param bool $isUnlimitedSize
     * @return string
     *
     * @throws GuzzleException
     * @throws FileIsTooLargeException
     * @throws SourceResourceTimedOutException
     * @throws SourceResourceIsInvalidException
     * @throws FailedToRetrieveFileException
     */
    protected function fetchRemoteFile(File $file, $target, string $cachedPath, int $maxBytes, bool $isUnlimitedSize): string
    {
        $sourceResource = null;

        try {
            $response = $this->client->get($this->encodeUrl($file->getUrl()), [
                'stream' => true,
                'on_headers' => function ($response) use ($maxBytes, $isUnlimitedSize) {
                    if (! $response instanceof ResponseInterface) {
                        return;
                    }
                    $contentLength = $response->getHeaderLine('content-length');
                    $contentBytes = is_numeric($contentLength) ? (int) $contentLength : null;

                    if (!$isUnlimitedSize && $contentBytes !== null && $contentBytes > $maxBytes) {
                        throw FileIsTooLargeException::create($maxBytes);
                    }
                },
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                throw FailedToRetrieveFileException::create(
                    "HTTP request failed with status code {$statusCode}"
                );
            }

            $responseBodyStream = $response->getBody();
            $sourceResource = $responseBodyStream->detach();

            if (!is_resource($sourceResource)) {
                throw SourceResourceIsInvalidException::create('Could not detach valid stream resource from response body.');
            }

            $this->copyStreamWithSizeLimit($sourceResource, $target, $maxBytes, $isUnlimitedSize, 'from remote source');

            return $cachedPath;
        } finally {
            if (is_resource($sourceResource)) {
                fclose($sourceResource);
            }
        }
    }

    /**
     * Cache a file from a storage disk and get the path to the cached file. Files
     * from local disks are not cached.
     *
     * @param File $file Cloud storage file
     * @param resource $target Target file resource
     *
     * @return string
     *
     * @throws FileNotFoundException
     * @throws FileIsTooLargeException
     * @throws SourceResourceIsInvalidException
     * @throws SourceResourceTimedOutException
     */
    protected function getDiskFile(File $file, $target): string
    {
        $parts = $this->splitByProtocol($file->getUrl());
        if (!isset($parts[1])) {
            throw new FileNotFoundException("Invalid file URL: {$file->getUrl()}");
        }

        $path = $parts[1];
        $disk = $this->getDisk($file);

        $source = $disk->readStream($path);
        if (is_null($source)) {
            throw new FileNotFoundException("Could not open file stream for path: {$path}");
        }

        try {
            return $this->cacheFromResource($file, $source, $target);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    /**
     * Store the file from the given resource to a cached file.
     *
     * @param File $file
     * @param resource $source
     * @param resource $target
     *
     * @return string Path to the cached file
     *
     * @throws SourceResourceIsInvalidException
     * @throws FileIsTooLargeException
     * @throws SourceResourceTimedOutException
     */
    protected function cacheFromResource(File $file, $source, $target): string
    {
        if (!is_resource($source)) {
            throw SourceResourceIsInvalidException::create('The source resource could not be established.');
        }

        $maxBytes = $this->config['max_file_size'];
        $isUnlimitedSize = $maxBytes < 0;

        $this->copyStreamWithSizeLimit($source, $target, $maxBytes, $isUnlimitedSize);

        return $this->getCachedPath($file);
    }

    /**
     * Copy stream with size limit and timeout handling.
     *
     * @param resource $source
     * @param resource $target
     * @param int $maxBytes
     * @param bool $isUnlimitedSize
     * @param string $errorContext Additional context for error messages
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

        if (!$isUnlimitedSize && $bytes > $maxBytes) {
            throw FileIsTooLargeException::create($maxBytes);
        }

        /** @var array<string, mixed> $metadata */
        $metadata = stream_get_meta_data($source);
        if (($metadata['timed_out'] ?? false) === true) {
            throw SourceResourceTimedOutException::create();
        }
    }

    /**
     * Get the path to the cached file.
     */
    protected function getCachedPath(File $file): string
    {
        $url = $file->getUrl();

        return $this->pathCache[$url] ??= "{$this->config['path']}/" . hash('sha256', $url);
    }

    /**
     * Get the storage disk on which a file is stored.
     */
    protected function getDisk(File $file): FilesystemAdapter
    {
        $parts = $this->splitByProtocol($file->getUrl());
        $diskName = $parts[0];
        /** @var FilesystemAdapter $disk */
        $disk = $this->storage->disk($diskName);

        return $disk;
    }

    /**
     * Creates the cache directory if it doesn't exist yet.
     */
    protected function ensurePathExists(): void
    {
        if (!$this->files->exists($this->config['path'])) {
            $this->files->makeDirectory($this->config['path'], 0755, true, true);
        }
    }

    /**
     * Determine if a file is remote, i.e. served by a public webserver.
     */
    protected function isRemote(File $file): bool
    {
        $scheme = parse_url($file->getUrl(), PHP_URL_SCHEME);

        if (!is_string($scheme)) {
            return false;
        }

        $normalized = strtolower($scheme);

        return $normalized === 'http' || $normalized === 'https';
    }

    /**
     * Split URL by protocol separator.
     *
     * @return array{0: string, 1?: string}
     */
    protected function splitByProtocol(string $url): array
    {
        $parts = explode('://', $url, 2);

        if (isset($parts[1])) {
            return [$parts[0], $parts[1]];
        }

        return [$parts[0]];
    }

    /**
     * Escape special characters (e.g. spaces) that may occur in parts of a HTTP URL.
     *
     * We encode spaces and other problematic characters while preserving + signs
     * since they have special meaning in URLs (especially query strings).
     */
    protected function encodeUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $this->encodeUrlUnsafeCharacters($url);
        }

        $encoded = strtolower($parts['scheme']) . '://';

        if (isset($parts['user'])) {
            $encoded .= $this->encodeUrlUnsafeCharacters($parts['user']);
            if (isset($parts['pass'])) {
                $encoded .= ':' . $this->encodeUrlUnsafeCharacters($parts['pass']);
            }
            $encoded .= '@';
        }

        $host = $parts['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        $encoded .= $host;

        if (isset($parts['port'])) {
            $encoded .= ':' . $parts['port'];
        }

        $encoded .= $this->encodeUrlUnsafeCharacters($parts['path'] ?? '');

        if (isset($parts['query'])) {
            $encoded .= '?' . $this->encodeUrlUnsafeCharacters($parts['query']);
        }

        if (isset($parts['fragment'])) {
            $encoded .= '#' . $this->encodeUrlUnsafeCharacters($parts['fragment']);
        }

        return $encoded;
    }

    /**
     * Encode unsafe URL characters in a single URL component.
     */
    protected function encodeUrlUnsafeCharacters(string $value): string
    {
        return preg_replace_callback(
            '/[^A-Za-z0-9\-._~:@!$&\'()*+,;=%\/?#]/',
            static fn(array $m): string => rawurlencode($m[0]),
            $value
        ) ?? $value;
    }

    /**
     * Remove userinfo (credentials) from a URL for safe logging.
     */
    protected function sanitizeUrlForLogging(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $sanitized = $parts['scheme'] . '://';
        if (isset($parts['user'])) {
            $sanitized .= '***';
            if (isset($parts['pass'])) {
                $sanitized .= ':***';
            }
            $sanitized .= '@';
        }
        $sanitized .= $parts['host'];
        if (isset($parts['port'])) {
            $sanitized .= ':' . $parts['port'];
        }
        $sanitized .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $sanitized .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $sanitized .= '#' . $parts['fragment'];
        }
        return $sanitized;
    }

    /**
     * Validate that a URL's host is in the allowed hosts list.
     *
     * @throws HostNotAllowedException
     */
    protected function validateHost(string $url): void
    {
        $allowedHosts = $this->config['allowed_hosts'];

        if ($allowedHosts === null) {
            return;
        }

        $parts = parse_url($url);
        if (!isset($parts['host'])) {
            throw HostNotAllowedException::create('(empty)');
        }

        $host = strtolower($parts['host']);

        foreach ((array) $allowedHosts as $allowedHost) {
            $allowedHost = strtolower(trim($allowedHost));

            if ($host === $allowedHost) {
                return;
            }

            if (str_starts_with($allowedHost, '*.')) {
                $domain = substr($allowedHost, 2);
                if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                    return;
                }
            }
        }

        throw HostNotAllowedException::create($host);
    }

    /**
     * Default callback for get() method.
     */
    protected static function defaultGetCallback(File $file, string $path): string
    {
        return $path;
    }

    /**
     * Default callback for batch() method.
     *
     * @param array<int, File> $files
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    protected static function defaultBatchCallback(array $files, array $paths): array
    {
        return $paths;
    }

    /**
     * Create a new Guzzle HTTP client.
     */
    protected function makeHttpClient(): Client
    {
        $timeout = max($this->config['timeout'], 0);
        $connectTimeout = max($this->config['connect_timeout'], 0);
        $readTimeout = max($this->config['read_timeout'], 0);

        return new Client([
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'read_timeout' => $readTimeout,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => $this->config['user_agent'],
            ],
            'allow_redirects' => [
                'max' => $this->config['max_redirects'],
                'on_redirect' => function (
                    \Psr\Http\Message\RequestInterface $request,
                    ResponseInterface $response,
                    \Psr\Http\Message\UriInterface $uri
                ) {
                    $this->validateHost((string) $uri);
                },
            ],
        ]);
    }
}
