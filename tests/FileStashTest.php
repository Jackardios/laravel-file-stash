<?php

namespace Jackardios\FileStash\Tests;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Jackardios\FileStash\Contracts\File;
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
use Jackardios\FileStash\Exceptions\InvalidConfigurationException;
use Jackardios\FileStash\Exceptions\LifecycleLockTimeoutException;
use Jackardios\FileStash\Exceptions\MimeTypeIsNotAllowedException;
use Jackardios\FileStash\FileStash;
use Jackardios\FileStash\GenericFile;
use Jackardios\FileStash\Support\CacheMetrics;
use Jackardios\FileStash\Support\DeleteResult;
use Jackardios\FileStash\Testing\FileStashFake;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use ReflectionMethod;

class FileStashTest extends TestCase
{
    protected string $cachePath;

    protected string $diskPath;

    protected \Closure $noop;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(8));
        $this->cachePath = sys_get_temp_dir().'/file_stash_test_'.$suffix;
        $this->diskPath = sys_get_temp_dir().'/file_stash_disk_'.$suffix;
        $this->noop = fn ($file, $path) => $path;

        $this->app['files']->makeDirectory($this->cachePath, 0755, false, true);
        $this->app['files']->makeDirectory($this->diskPath, 0755, false, true);

        config(['filesystems.disks.test' => [
            'driver' => 'local',
            'root' => $this->diskPath,
        ]]);

        config(['filesystems.disks.fixtures' => [
            'driver' => 'local',
            'root' => __DIR__.'/files',
        ]]);
    }

    protected function tearDown(): void
    {
        if ($this->app['files']->exists($this->cachePath)) {
            $this->app['files']->deleteDirectory($this->cachePath);
        }
        if ($this->app['files']->exists($this->diskPath)) {
            $this->app['files']->deleteDirectory($this->diskPath);
        }
        parent::tearDown();
    }

    /**
     * Create a FileStash instance with default path.
     */
    protected function createCache(array $config = []): FileStash
    {
        return new FileStash(array_merge(['path' => $this->cachePath], $config));
    }

    /**
     * Create a FileStash with a mock HTTP client.
     */
    protected function createCacheWithMockClient(array $responses, array $config = [], bool $httpErrors = false): FileStash
    {
        $mock = new MockHandler($responses);
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => $httpErrors,
        ]);

        return new FileStash(array_merge(['path' => $this->cachePath], $config), $client);
    }

    /**
     * A handler that streams bodies like the curl handler: on_headers first,
     * then the body into the sink, and a short sink write aborts the
     * transfer (CURLE_WRITE_ERROR). MockHandler ignores short writes.
     *
     * @param  array<int, ResponseInterface>  $responses
     */
    protected function curlLikeHandler(array $responses): callable
    {
        return static function (RequestInterface $request, array $options) use (&$responses) {
            $response = array_shift($responses);

            try {
                ($options['on_headers'] ?? static fn () => null)($response);
            } catch (\Throwable $exception) {
                return Create::rejectionFor(new RequestException(
                    message: 'An error was encountered during the on_headers event',
                    request: $request,
                    previous: $exception,
                ));
            }

            $sink = $options['sink'] ?? null;
            if ($sink instanceof StreamInterface) {
                $body = (string) $response->getBody();
                if ($body !== '' && $sink->write($body) < strlen($body)) {
                    return Create::rejectionFor(new RequestException(
                        message: 'cURL error 23: Failure writing output to destination',
                        request: $request,
                    ));
                }
                $response = $response->withBody($sink);
            }

            return Create::promiseFor($response);
        };
    }

    /**
     * Get the cached path for a file URL.
     */
    protected function getCachedPath(string $url): string
    {
        return "{$this->cachePath}/".hash('sha256', $url);
    }

    /**
     * Create a mock S3 filesystem.
     */
    protected function mockS3Filesystem($stream): void
    {
        config(['filesystems.disks.s3' => ['driver' => 's3']]);

        $filesystemManagerMock = $this->createStub(FilesystemManager::class);
        $filesystemMock = $this->createStub(FilesystemAdapter::class);
        $filesystemMock->method('readStream')->willReturn($stream);
        $filesystemMock->method('getDriver')->willReturn($filesystemMock);
        $filesystemMock->method('get')->willReturn($filesystemMock);
        $filesystemManagerMock->method('disk')->willReturn($filesystemMock);
        $this->app['filesystem'] = $filesystemManagerMock;
    }

    /**
     * Get test image content.
     */
    protected function getTestImageContent(): string
    {
        return file_get_contents(__DIR__.'/files/test-image.jpg');
    }

    /**
     * Get the default Guzzle client's config from a FileStash instance (via
     * its RemoteFetcher). Reflection is the only way to observe the default
     * client without a real HTTP server; per-request options are captured
     * with captureRequestOptions() instead.
     */
    protected function getClientConfig(FileStash $cache): array
    {
        $fetcherProperty = new \ReflectionProperty($cache, 'remoteFetcher');
        $fetcher = $fetcherProperty->getValue($cache);

        return (new ReflectionMethod($fetcher, 'client'))->invoke($fetcher)->getConfig();
    }

    /**
     * The options the cache passes to the HTTP handler for a request (after
     * Guzzle merged them with the client config), captured from a HEAD.
     */
    protected function captureRequestOptions(array $config = []): array
    {
        $captured = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200)]));
        $stack->push(function (callable $handler) use (&$captured) {
            return function (RequestInterface $request, array $options) use ($handler, &$captured) {
                $captured = $options;

                return $handler($request, $options);
            };
        });

        $cache = new FileStash(array_merge(['path' => $this->cachePath], $config), new Client(['handler' => $stack]));
        $cache->exists(new GenericFile('https://files/image.jpg'));

        return $captured;
    }

    public function testGetExists()
    {
        $cache = $this->createCache(['touch_interval' => 0]);
        $url = 'abc://some/image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        copy(__DIR__.'/files/test-image.jpg', $cachedPath);
        $this->assertTrue(touch($cachedPath, time() - 1));
        $fileatime = fileatime($cachedPath);
        $this->assertNotEquals(time(), $fileatime);

        $result = $cache->get($file, fn ($file, $path) => $file);

        $this->assertInstanceof(File::class, $result);
        clearstatcache();
        $this->assertNotEquals($fileatime, fileatime($cachedPath));
    }

    public function testGetRemote()
    {
        $url = 'https://files/image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $cache = $this->createCacheWithMockClient([
            new Response(200, [], $this->getTestImageContent()),
        ]);

        $this->assertFileDoesNotExist($cachedPath);
        $path = $cache->get($file, $this->noop);
        $this->assertEquals($cachedPath, $path);
        $this->assertFileExists($cachedPath);
    }

    public function testGetRemoteTooLarge()
    {
        $url = 'https://files/image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $cache = $this->createCacheWithMockClient([
            new Response(200, ['Content-Length' => '100'], $this->getTestImageContent()),
        ], ['max_file_size' => 1]);

        try {
            $cache->get($file, $this->noop);
            $this->fail('Expected FileIsTooLargeException to be thrown.');
        } catch (FileIsTooLargeException $exception) {
            $this->assertFileDoesNotExist($cachedPath);
        }
    }

    public function testGetRemoteTooLargeWithoutContentLength()
    {
        // No Content-Length: the limit can only be enforced while streaming.
        $url = 'https://files/image.jpg';
        $cache = $this->createCacheWithMockClient([
            new Response(200, [], str_repeat('x', 101)),
        ], ['max_file_size' => 100]);

        try {
            $cache->get(new GenericFile($url), $this->noop);
            $this->fail('Expected FileIsTooLargeException to be thrown.');
        } catch (FileIsTooLargeException $exception) {
            $this->assertSame(100, $exception->maxBytes);
        }

        $this->assertSame([], glob($this->cachePath.'/*') ?: []);
    }

    public function testGetRemoteExactlyAtTheLimitWithoutContentLength()
    {
        $cache = $this->createCacheWithMockClient([
            new Response(200, [], str_repeat('x', 100)),
        ], ['max_file_size' => 100]);

        $path = $cache->get(new GenericFile('https://files/image.jpg'));

        $this->assertSame(100, filesize($path));
    }

    public function testGetDiskDoesNotExist()
    {
        $file = new GenericFile('abc://files/image.jpg');
        $cache = $this->createCache();

        // Laravel's own exception; only its class and the disk name are
        // stable across framework versions.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('abc');
        $cache->get($file, $this->noop);
    }

    public function testGetDiskLocal()
    {
        $this->app['files']->put("{$this->diskPath}/test-image.jpg", 'abc');
        $url = 'test://test-image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);
        $cache = $this->createCache();

        $path = $cache->get($file, $this->noop);
        $this->assertEquals($cachedPath, $path);
        $this->assertFileExists($cachedPath);
    }

    public function testGetDiskLocalDoesNotExist()
    {
        $file = new GenericFile('test://test-image.jpg');
        $cache = $this->createCache();

        $this->expectException(FileNotFoundException::class);
        $cache->get($file, $this->noop);
    }

    public function testGetDiskCloud()
    {
        $url = 's3://files/test-image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $stream = fopen(__DIR__.'/files/test-image.jpg', 'rb');
        $this->mockS3Filesystem($stream);

        $cache = $this->createCache();

        $this->assertFileDoesNotExist($cachedPath);
        $path = $cache->get($file, $this->noop);
        $this->assertEquals($cachedPath, $path);
        $this->assertFileExists($cachedPath);
        $this->assertFalse(is_resource($stream));
    }

    public function testGetDiskCloudTooLarge()
    {
        $url = 's3://files/test-image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $stream = fopen(__DIR__.'/files/test-image.jpg', 'rb');
        $this->mockS3Filesystem($stream);

        $cache = $this->createCache(['max_file_size' => 1]);

        try {
            $cache->get($file, $this->noop);
            $this->fail('Expected FileIsTooLargeException to be thrown.');
        } catch (FileIsTooLargeException $exception) {
            $this->assertFileDoesNotExist($cachedPath);
            $this->assertFalse(is_resource($stream));
        }
    }

    public function testGetThrowOnLock()
    {
        $cache = $this->createCache();
        $url = 'abc://some/image.jpg';
        $file = new GenericFile($url);
        $path = $this->getCachedPath($url);
        touch($path, time() - 1);

        $handle = fopen($path, 'w');
        flock($handle, LOCK_EX);

        try {
            $this->expectException(FileLockedException::class);
            $cache->get($file, fn ($file, $path) => $file, true);
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    public function testZeroByteEntryIsServed()
    {
        // The empty mock queue proves no refetch happens: any request would
        // throw on queue exhaustion.
        $cache = $this->createCacheWithMockClient([]);
        $url = 'https://files/empty.bin';
        $cachedPath = $this->getCachedPath($url);

        touch($cachedPath);

        $path = $cache->get(new GenericFile($url), $this->noop);

        $this->assertSame($cachedPath, $path);
        $this->assertSame(0, filesize($cachedPath));
    }

    public function testEmptyRemoteBodyIsCached()
    {
        $cache = $this->createCacheWithMockClient([
            new Response(200, [], ''),
        ]);
        $url = 'https://files/empty.bin';
        $file = new GenericFile($url);

        $path = $cache->get($file, $this->noop);
        $this->assertFileExists($path);
        $this->assertSame(0, filesize($path));

        // Second get must be a cache hit: the queue is exhausted, so a
        // refetch would throw.
        $this->assertSame($path, $cache->get($file, $this->noop));
    }

    public function testEmptyBodyWithMimeWhitelistIsRejected()
    {
        // Deny by default: finfo cannot prove an empty file passes the
        // whitelist (it reports an artificial empty-file type).
        $cache = $this->createCacheWithMockClient([
            new Response(200, [], ''),
        ], ['mime_types' => ['image/jpeg']]);

        $this->expectException(MimeTypeIsNotAllowedException::class);
        $cache->get(new GenericFile('https://files/empty.bin'), $this->noop);
    }

    public function testThrowOnLockFailsFastWhileAnotherWorkerDownloads()
    {
        // Cold entry, claim held: another worker is downloading it right now.
        // The empty mock queue proves nothing is downloaded here.
        $cache = $this->createCacheWithMockClient([], ['lock_wait_timeout' => 5]);
        $url = 'https://files/image.jpg';
        $claimPath = "{$this->cachePath}/.locks/".basename($this->getCachedPath($url)).'.lock';
        $this->app['files']->makeDirectory(dirname($claimPath), 0777, true, true);
        $claim = fopen($claimPath, 'c+');
        $this->assertTrue(flock($claim, LOCK_EX));
        $start = microtime(true);

        try {
            $cache->get(new GenericFile($url), $this->noop, true);
            $this->fail('Expected FileLockedException to be thrown.');
        } catch (FileLockedException) {
            $this->assertLessThan(1.0, microtime(true) - $start, 'throwOnLock must not wait for the claim.');
        } finally {
            fclose($claim);
        }

        $this->assertFileDoesNotExist($this->getCachedPath($url));
    }

    public function testThrowOnLockContentionIsNotCountedAsError()
    {
        $cache = $this->createCache();
        $url = 'fixtures://test-image.jpg';
        $cachedPath = $this->getCachedPath($url);
        copy(__DIR__.'/files/test-image.jpg', $cachedPath);

        $handle = fopen($cachedPath, 'rb');
        $this->assertTrue(flock($handle, LOCK_EX));

        try {
            $cache->get(new GenericFile($url), $this->noop, true);
            $this->fail('Expected FileLockedException to be thrown.');
        } catch (FileLockedException) {
            // Expected contention signal, not an error.
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->assertSame(0, $cache->metrics()->errors);
    }

    public function testGetOnce()
    {
        $url = 'fixtures://test-image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $cache = $this->createCache();
        $result = $cache->getOnce($file, fn ($file, $path) => $file);

        $this->assertInstanceof(File::class, $result);
        $this->assertFileDoesNotExist($cachedPath);
    }

    public function testBatch()
    {
        $this->app['files']->put("{$this->diskPath}/test-image.jpg", 'abc');
        $url = 'test://test-image.jpg';
        $file = new GenericFile($url);
        $file2 = new GenericFile($url);
        $hash = hash('sha256', $url);

        $cache = $this->createCache();
        $paths = $cache->batch([$file, $file2], fn ($files, $paths) => $paths);

        $this->assertCount(2, $paths);
        $this->assertStringContainsString($hash, $paths[0]);
        $this->assertStringContainsString($hash, $paths[1]);
    }

    public function testBatchThrowOnLock()
    {
        $cache = $this->createCache();
        $url = 'abc://some/image.jpg';
        $file = new GenericFile($url);
        $path = $this->getCachedPath($url);
        touch($path, time() - 1);

        $handle = fopen($path, 'w');
        flock($handle, LOCK_EX);

        try {
            $this->expectException(FileLockedException::class);
            $cache->batch([$file], fn ($file, $path) => $file, true);
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    public function testBatchOnce()
    {
        $url = 'fixtures://test-image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $this->createCache()->batchOnce([$file], $this->noop);

        $this->assertFileDoesNotExist($cachedPath);
    }

    public function testPrune()
    {
        $this->app['files']->put($this->getCachedPath('abc'), 'abc');
        touch($this->getCachedPath('abc'), time() - 1);
        $this->app['files']->put($this->getCachedPath('def'), 'def');

        $cache = $this->createCache(['max_size' => 3]);
        $cache->prune();

        $this->assertFileDoesNotExist($this->getCachedPath('abc'));
        $this->assertFileExists($this->getCachedPath('def'));

        $cache = $this->createCache(['max_size' => 0]);
        $cache->prune();

        $this->assertFileDoesNotExist($this->getCachedPath('def'));
    }

    public function testPruneAge()
    {
        $this->app['files']->put($this->getCachedPath('abc'), 'abc');
        touch($this->getCachedPath('abc'), time() - 61);
        $this->app['files']->put($this->getCachedPath('def'), 'def');

        $cache = $this->createCache(['max_age' => 1]);
        $cache->prune();

        $this->assertFileDoesNotExist($this->getCachedPath('abc'));
        $this->assertFileExists($this->getCachedPath('def'));
    }

    #[DataProvider('prunePhaseProvider')]
    public function testPruneSkipsEntriesReadAfterCollection(array $config)
    {
        $entry = $this->getCachedPath('https://example.com/entry');
        $this->app['files']->put($entry, 'entry');
        touch($entry, time() - 7200);

        // A worker reads (and touches) the entry after prune collected its
        // atime but before prune got to delete it.
        $cache = new class(['path' => $this->cachePath, ...$config]) extends FileStash
        {
            protected function deleteEntry(string $path, string $evictionReason = 'pruned', ?callable $verify = null): DeleteResult
            {
                touch($path);

                return parent::deleteEntry($path, $evictionReason, $verify);
            }
        };

        $stats = $cache->prune();

        $this->assertFileExists($entry);
        $this->assertSame(0, $stats['deleted']);
        $this->assertSame(1, $stats['remaining']);
    }

    public static function prunePhaseProvider(): array
    {
        return [
            'age-based' => [['max_age' => 1]],
            'size-based' => [['max_size' => 0]],
        ];
    }

    public function testPruneKeepsEntriesOfARunningChunkedBatch()
    {
        $this->app['files']->put("{$this->diskPath}/third.txt", 'third');
        $files = [
            new GenericFile('fixtures://test-file.txt'),
            new GenericFile('fixtures://test-image.jpg'),
            new GenericFile('test://third.txt'),
        ];
        $cache = $this->createCache(['batch_chunk_size' => 1]);
        // A second instance stands in for the scheduled prune of another
        // worker; max_size 0 makes every entry an eviction candidate.
        $pruner = $this->createCache(['max_size' => 0]);

        $existing = $cache->batch($files, function ($files, $paths) use ($pruner) {
            $stats = $pruner->prune();
            $this->assertFalse($stats['completed']);

            return array_map('file_exists', $paths);
        });

        $this->assertSame([true, true, true], $existing);

        // Once the batch is over, prune evicts as usual.
        $this->assertSame(3, $pruner->prune()['deleted']);
    }

    public function testCreatedFilesAndDirectoriesRespectUmask()
    {
        // A group-writable umask plus a setgid directory is how the web
        // server and queue workers of different users share one cache.
        $previous = umask(0002);

        try {
            $path = "{$this->cachePath}/nested/cache";
            $cache = new FileStash(['path' => $path, 'batch_chunk_size' => 1]);
            $cache->batch([new GenericFile('fixtures://test-file.txt'), new GenericFile('fixtures://test-image.jpg')]);
            $cache->prune();
        } finally {
            umask($previous);
        }

        $this->assertSame(0775, fileperms("{$this->cachePath}/nested") & 0777);
        $this->assertSame(0775, fileperms($path) & 0777);
        $this->assertSame(0775, fileperms("{$path}/.locks") & 0777);
        foreach (['.lifecycle.lock', '.pin.lock', hash('sha256', 'fixtures://test-file.txt')] as $name) {
            $this->assertSame(0664, fileperms("{$path}/{$name}") & 0777, $name);
        }
    }

    public function testReadOnlyLockFilesCreatedByAnotherUserStillWork()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }

        $urls = ['fixtures://test-file.txt', 'fixtures://test-image.jpg'];
        $this->app['files']->makeDirectory("{$this->cachePath}/.locks", 0777, true, true);
        $lockFiles = [
            "{$this->cachePath}/.lifecycle.lock",
            "{$this->cachePath}/.pin.lock",
            "{$this->cachePath}/.locks/".hash('sha256', $urls[0]).'.lock',
        ];
        foreach ($lockFiles as $lockFile) {
            touch($lockFile);
            chmod($lockFile, 0444);
        }

        $cache = $this->createCache(['batch_chunk_size' => 1]);
        $sizes = $cache->batch(
            array_map(fn (string $url) => new GenericFile($url), $urls),
            fn ($files, $paths) => array_map('filesize', $paths)
        );

        $this->assertSame([filesize(__DIR__.'/files/test-file.txt'), filesize(__DIR__.'/files/test-image.jpg')], $sizes);
        $this->assertTrue($cache->prune()['completed']);
    }

    public function testRetrieveThrowsFailedToRetrieveFileExceptionAfterMaxAttempts()
    {
        $url = 'fixtures://test-file.txt';
        $cachedPath = $this->getCachedPath($url);
        $cache = $this->createCache(['lock_wait_timeout' => 0, 'lock_max_attempts' => 3]);

        // Another worker holds the download claim and never publishes.
        $claimPath = "{$this->cachePath}/.locks/".basename($cachedPath).'.lock';
        $this->app['files']->makeDirectory(dirname($claimPath), 0777, true, true);
        $claim = fopen($claimPath, 'c');
        $this->assertTrue(flock($claim, LOCK_EX));

        try {
            $this->expectException(FailedToRetrieveFileException::class);
            $this->expectExceptionMessage('Failed to retrieve file after 3 attempts');

            $cache->get(new GenericFile($url));
        } finally {
            fclose($claim);
            $this->assertFileDoesNotExist($cachedPath);
            $this->assertSame(1, $cache->metrics()->errors);
        }
    }

    public function testPruneAndClearOnlyTouchCacheEntries()
    {
        $hash = hash('sha256', 'https://example.com/foreign');
        $foreign = [
            "{$this->cachePath}/README.txt",
            "{$this->cachePath}/{$hash}.bak",
            "{$this->cachePath}/sub/deeper/user-upload.jpg",
            "{$this->cachePath}/sub/{$hash}",
            "{$this->cachePath}/sub/{$hash}.1.0123456789abcdef.tmp",
        ];
        foreach ($foreign as $path) {
            $this->app['files']->makeDirectory(dirname($path), 0755, true, true);
            $this->app['files']->put($path, 'not a cache entry');
            touch($path, time() - 7200);
        }

        $entry = $this->getCachedPath('https://example.com/entry');
        $this->app['files']->put($entry, 'entry');
        touch($entry, time() - 7200);

        $cache = $this->createCache(['max_age' => 1, 'max_size' => 0]);
        $stats = $cache->prune();

        $this->assertFileDoesNotExist($entry);
        $this->assertSame(1, $stats['deleted']);
        $this->assertSame(0, $stats['remaining']);

        $this->app['files']->put($entry, 'entry');
        $cache->clear();

        $this->assertFileDoesNotExist($entry);
        foreach ($foreign as $path) {
            $this->assertFileExists($path);
        }
    }

    public function testClearRemovesOrphanedTempFilesWithoutEvictionEvents()
    {
        $dispatched = [];
        $dispatcher = $this->createStub(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });
        $cache = new FileStash(['path' => $this->cachePath, 'events_enabled' => true], null, null, null, null, $dispatcher);

        $entry = $this->getCachedPath('https://example.com/entry');
        $temp = "{$entry}.123.0123456789abcdef.tmp";
        $this->app['files']->put($entry, 'entry');
        $this->app['files']->put($temp, 'partial');

        $cache->clear();

        $this->assertFileDoesNotExist($entry);
        $this->assertFileDoesNotExist($temp);
        $this->assertSame(1, $cache->metrics()->evictions);
        $evicted = array_values(array_filter($dispatched, fn ($e) => $e instanceof CacheFileEvicted));
        $this->assertCount(1, $evicted);
        $this->assertSame($entry, $evicted[0]->path);
    }

    public function testClear()
    {
        $this->app['files']->put($this->getCachedPath('abc'), 'abc');
        $this->app['files']->put($this->getCachedPath('def'), 'abc');

        $handle = fopen($this->getCachedPath('def'), 'rb');
        flock($handle, LOCK_SH);

        try {
            $this->createCache()->clear();

            $this->assertFileExists($this->getCachedPath('def'));
            $this->assertFileDoesNotExist($this->getCachedPath('abc'));
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    public function testMimeTypeWhitelist()
    {
        $cache = $this->createCache(['mime_types' => ['image/jpeg']]);

        // Should work for allowed MIME type
        $cache->get(new GenericFile('fixtures://test-image.jpg'), $this->noop);

        // Should throw for disallowed MIME type
        $this->expectException(MimeTypeIsNotAllowedException::class);
        $this->expectExceptionMessage('text/plain');
        $cache->get(new GenericFile('fixtures://test-file.txt'), $this->noop);
    }

    public function testMimeCheckReadsTheLockedTempHandle()
    {
        // On Windows file locks are mandatory: reopening the exclusively
        // locked temp file by path (finfo on the path) fails. The MIME check
        // must use the descriptor that holds the lock.
        $files = new class extends Filesystem
        {
            public function mimeType($path)
            {
                throw new \RuntimeException("reopened '{$path}' by path");
            }
        };
        $cache = new FileStash(['path' => $this->cachePath, 'mime_types' => ['image/jpeg']], null, $files);

        $path = $cache->get(new GenericFile('fixtures://test-image.jpg'));

        $this->assertFileEquals(__DIR__.'/files/test-image.jpg', $path);
    }

    public function testExistsDisk()
    {
        $file = new GenericFile('test://test-image.jpg');
        $cache = $this->createCache();

        $this->assertFalse($cache->exists($file));
        $this->app['files']->put("{$this->diskPath}/test-image.jpg", 'abc');
        $this->assertTrue($cache->exists($file));
    }

    public function testExistsDiskTooLarge()
    {
        $this->app['files']->put("{$this->diskPath}/test-image.jpg", 'abc');
        $file = new GenericFile('test://test-image.jpg');
        $cache = $this->createCache(['max_file_size' => 1]);

        $this->expectException(FileIsTooLargeException::class);
        $cache->exists($file);
    }

    public function testExistsDiskMimeNotAllowed()
    {
        $this->app['files']->put("{$this->diskPath}/test-file.txt", 'abc');
        $file = new GenericFile('test://test-file.txt');
        $cache = $this->createCache(['mime_types' => ['image/jpeg']]);

        $this->expectException(MimeTypeIsNotAllowedException::class);
        $this->expectExceptionMessage('text/plain');
        $cache->exists($file);
    }

    public function testExistsRemote404()
    {
        $file = new GenericFile('https://example.com/file');
        $cache = $this->createCacheWithMockClient([new Response(404)]);

        $this->assertFalse($cache->exists($file));
    }

    public function testExistsRemote404WithHttpErrorsEnabled()
    {
        $file = new GenericFile('https://example.com/file');
        $cache = $this->createCacheWithMockClient([new Response(404)], [], true);

        $this->assertFalse($cache->exists($file));
    }

    #[DataProvider('definitiveNegativeStatusProvider')]
    public function testExistsRemoteReturnsFalseForDefinitiveAnswers(int $status, bool $httpErrors)
    {
        $file = new GenericFile('https://example.com/file');
        $cache = $this->createCacheWithMockClient([new Response($status)], ['max_redirects' => 0], $httpErrors);

        $this->assertFalse($cache->exists($file));
    }

    public static function definitiveNegativeStatusProvider(): array
    {
        return [
            '301 redirect not followed' => [301, false],
            '403 forbidden' => [403, false],
            '410 gone' => [410, true],
        ];
    }

    #[DataProvider('transientStatusProvider')]
    public function testExistsRemoteThrowsForTransientFailures(int $status, bool $httpErrors)
    {
        // A server error or rate limit says nothing about the file: reporting
        // it as missing would make callers delete or skip existing files.
        $file = new GenericFile('https://example.com/file');
        $cache = $this->createCacheWithMockClient([new Response($status), new Response($status)], [
            'http_retries' => 1,
            'http_retry_delay' => 1,
        ], $httpErrors);

        try {
            $cache->exists($file);
            $this->fail('Expected FailedToRetrieveFileException was not thrown');
        } catch (FailedToRetrieveFileException $exception) {
            $this->assertSame($status, $exception->statusCode);
        }
    }

    public static function transientStatusProvider(): array
    {
        return [
            '500 server error' => [500, false],
            '503 with http_errors' => [503, true],
            '429 rate limited' => [429, false],
        ];
    }

    public function testExistsRemoteReturnsFalseOnTooManyRedirects()
    {
        $file = new GenericFile('https://example.com/file');
        $cache = $this->createCacheWithMockClient([
            new Response(302, ['Location' => 'https://example.com/a']),
            new Response(302, ['Location' => 'https://example.com/b']),
        ], ['max_redirects' => 1]);

        $this->assertFalse($cache->exists($file));
    }

    public function testExistsRemoteRetriesOnServerErrorWithHttpErrorsEnabled()
    {
        $file = new GenericFile('https://example.com/file');
        $cache = $this->createCacheWithMockClient([
            new Response(500),
            new Response(200),
        ], [
            'http_retries' => 1,
            'http_retry_delay' => 1,
        ], true);

        $this->assertTrue($cache->exists($file));
    }

    public function testExistsRemoteRetriesOnServerError()
    {
        $file = new GenericFile('https://example.com/file');
        $cache = $this->createCacheWithMockClient([
            new Response(500),
            new Response(200),
        ], [
            'http_retries' => 1,
            'http_retry_delay' => 1,
        ]);

        $this->assertTrue($cache->exists($file));
    }

    public function testExistsRemoteRetriesOn429()
    {
        $file = new GenericFile('https://example.com/file');
        $cache = $this->createCacheWithMockClient([
            new Response(429),
            new Response(200),
        ], [
            'http_retries' => 1,
            'http_retry_delay' => 1,
        ]);

        $this->assertTrue($cache->exists($file));
    }

    public function testExistsRemoteRetriesOnConnectException()
    {
        $url = 'https://example.com/file';
        $file = new GenericFile($url);
        $request = new Request('HEAD', $url);
        $connectException = new ConnectException('Temporary network error', $request);

        $cache = $this->createCacheWithMockClient([
            $connectException,
            new Response(200),
        ], [
            'http_retries' => 1,
            'http_retry_delay' => 1,
        ]);

        $this->assertTrue($cache->exists($file));
    }

    public function testRetryLogRedactsCredentialsAndQueryStrings()
    {
        // Presigned URLs carry their signature in the query string, and
        // Guzzle's network-error messages repeat the full request URI.
        $url = 'https://user:pass@files/image.jpg?X-Amz-Signature=s3cr3t&token=t0k3n#frag';
        $logger = new RecordingLogger;
        $mock = new MockHandler([
            new ConnectException(
                'cURL error 28: Operation timed out (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.$url,
                new Request('GET', $url)
            ),
            new Response(200, [], 'body'),
        ]);
        $cache = new FileStash(
            ['path' => $this->cachePath, 'http_retries' => 1, 'http_retry_delay' => 1],
            new Client(['handler' => HandlerStack::create($mock)]),
            logger: $logger
        );

        $cache->get(new GenericFile($url), $this->noop);

        $this->assertCount(1, $logger->records);
        $logged = json_encode($logger->records, JSON_UNESCAPED_SLASHES);
        foreach (['s3cr3t', 't0k3n', 'pass', 'frag'] as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
        $this->assertSame('https://***:***@files/image.jpg?X-Amz-Signature=***&token=***', $logger->records[0]['context']['url']);
        $this->assertStringContainsString('for https://***:***@files/image.jpg?X-Amz-Signature=***&token=***', $logger->records[0]['context']['exception']);
    }

    public function testExistsRemoteThrowsAfterConnectRetriesExhausted()
    {
        $url = 'https://example.com/file';
        $file = new GenericFile($url);
        $request = new Request('HEAD', $url);
        $connectException = new ConnectException('Temporary network error', $request);

        $cache = $this->createCacheWithMockClient([
            $connectException,
            $connectException,
        ], [
            'http_retries' => 1,
            'http_retry_delay' => 1,
        ]);

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Temporary network error');
        $cache->exists($file);
    }

    public function testExistsRemote200()
    {
        $file = new GenericFile('https://example.com/file');
        $cache = $this->createCacheWithMockClient([new Response(200)]);

        $this->assertTrue($cache->exists($file));
    }

    public function testExistsRemoteTooLarge()
    {
        $file = new GenericFile('https://example.com/file');
        $cache = $this->createCacheWithMockClient(
            [new Response(200, ['content-length' => '100'])],
            ['max_file_size' => 1]
        );

        $this->expectException(FileIsTooLargeException::class);
        $cache->exists($file);
    }

    public function testExistsRemoteMimeNotAllowed()
    {
        $file = new GenericFile('https://example.com/file');
        $cache = $this->createCacheWithMockClient(
            [new Response(200, ['content-type' => 'application/json'])],
            ['mime_types' => ['image/jpeg']]
        );

        $this->expectException(MimeTypeIsNotAllowedException::class);
        $this->expectExceptionMessage('application/json');
        $cache->exists($file);
    }

    public function testExistsRemoteTimeout()
    {
        $url = 'https://files.example.com/image.jpg';
        $file = new GenericFile($url);
        $request = new Request('HEAD', $url);
        $connectException = new ConnectException('Example of connection failed', $request);

        $cache = $this->createCacheWithMockClient([$connectException]);

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Example of connection failed');
        $cache->exists($file);
    }

    public function testGetRemoteThrowsConnectExceptionOnGetRequest()
    {
        $url = 'https://files.example.com/image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);
        $request = new Request('GET', $url);
        $connectException = new ConnectException('Example of connection failed', $request);

        $cache = $this->createCacheWithMockClient([$connectException]);

        try {
            $this->expectException(ConnectException::class);
            $this->expectExceptionMessage('Example of connection failed');
            $cache->get($file, $this->noop);
        } finally {
            $this->assertFileDoesNotExist($cachedPath);
        }
    }

    public function testPruneSkipsLockedFile()
    {
        $unlockedFile = $this->getCachedPath('unlocked');
        $lockedFile = $this->getCachedPath('locked');

        $this->app['files']->put($unlockedFile, 'delete me');
        touch($unlockedFile, time() - 100);
        clearstatcache(true, $unlockedFile);

        $this->app['files']->put($lockedFile, 'keep me');
        touch($lockedFile, time() - 100);
        clearstatcache(true, $lockedFile);

        $handle = fopen($lockedFile, 'rb');
        $this->assertTrue(flock($handle, LOCK_SH));

        $cache = $this->createCache(['max_age' => 1, 'max_size' => 1000 ** 2]);
        $cache->prune();

        $this->assertFileDoesNotExist($unlockedFile, 'Unlocked file should be pruned.');
        $this->assertFileExists($lockedFile, 'Locked file should NOT be pruned.');

        flock($handle, LOCK_UN);
        fclose($handle);

        $cache->prune();
        $this->assertFileDoesNotExist($lockedFile, 'Unlocked file should be pruned now.');
    }

    public function testGetOnceDoesNotDeleteLockedFile()
    {
        $url = 'fixtures://test-image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);
        $cache = $this->createCache();

        $handle = null;
        $result = $cache->getOnce($file, function ($file, $path) use (&$handle) {
            $handle = fopen($path, 'rb');
            $this->assertTrue(flock($handle, LOCK_SH));

            return $path;
        });

        $this->assertEquals($cachedPath, $result);
        $this->assertFileExists($cachedPath);
        $this->assertNotNull($handle);

        flock($handle, LOCK_UN);
        fclose($handle);

        $this->assertTrue($this->app['files']->delete($cachedPath));
    }

    public function testMultipleReadersAccessCachedFileSimultaneously()
    {
        $url = 'fixtures://test-file.txt';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $cache = $this->createCache();
        $initialPath = $cache->get($file, $this->noop);
        $this->assertEquals($cachedPath, $initialPath);
        $this->assertFileExists($cachedPath);

        // Simulate the first reader holding LOCK_SH
        $reader1Handle = fopen($cachedPath, 'rb');
        $this->assertTrue(flock($reader1Handle, LOCK_SH), 'Reader 1 failed to acquire LOCK_SH.');

        // The second reader tries to get the file (must successfully get LOCK_SH)
        $reader2Handle = null;
        $reader2Path = $cache->get($file, function ($file, $path) use (&$reader2Handle) {
            $reader2Handle = fopen($path, 'rb');
            $this->assertTrue(flock($reader2Handle, LOCK_SH), 'Reader 2 failed to acquire LOCK_SH.');

            return $path;
        });

        $this->assertEquals($cachedPath, $reader2Path);
        $this->assertIsResource($reader2Handle);

        flock($reader1Handle, LOCK_UN);
        fclose($reader1Handle);
        flock($reader2Handle, LOCK_UN);
        fclose($reader2Handle);
    }

    public function testConfigValidationThrowsOnInvalidMaxFileSize()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('max_file_size');

        new FileStash([
            'path' => $this->cachePath,
            'max_file_size' => -2, // Invalid: must be -1 or positive
        ]);
    }

    public function testConfigValidationThrowsOnInvalidMaxAge()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('max_age');

        new FileStash([
            'path' => $this->cachePath,
            'max_age' => 0, // Invalid: must be at least 1
        ]);
    }

    public function testConfigValidationThrowsOnInvalidLockMaxAttempts()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('lock_max_attempts');

        new FileStash([
            'path' => $this->cachePath,
            'lock_max_attempts' => 0, // Invalid: must be at least 1
        ]);
    }

    public function testGenericFileThrowsOnEmptyUrl()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');

        new GenericFile('');
    }

    public function testExistsRemoteIpv6Host()
    {
        $file = new GenericFile('http://[::1]/file');
        $cache = $this->createCacheWithMockClient([new Response(200)]);

        $this->assertTrue($cache->exists($file));
    }

    public function testExistsRemoteUppercaseScheme()
    {
        $file = new GenericFile('HTTPS://example.com/file');
        $cache = $this->createCacheWithMockClient([new Response(200)]);

        $this->assertTrue($cache->exists($file));
    }

    /**
     * Chunked batches release each chunk's per-file shared locks before the
     * next chunk (bounding open descriptors), so no entry is locked while the
     * callback runs; unchunked batches keep every entry locked. flock locks
     * belong to the open file description, so a second descriptor in this
     * process probes them like another process would.
     */
    #[DataProvider('chunkingProvider')]
    public function testBatchChunkingControlsEntryLocksDuringCallback(string $method, int $chunkSize, bool $locked)
    {
        $files = [];
        for ($i = 0; $i < 5; $i++) {
            $this->app['files']->put("{$this->diskPath}/chunk-file-{$i}.txt", "content-{$i}");
            $files[] = new GenericFile("test://chunk-file-{$i}.txt");
        }

        $cache = $this->createCache(['batch_chunk_size' => $chunkSize]);

        $contents = $cache->{$method}($files, function ($receivedFiles, $paths) use ($locked) {
            $this->assertSame(array_keys($receivedFiles), array_keys($paths));

            foreach ($paths as $path) {
                $probe = fopen($path, 'rb');
                $this->assertSame(! $locked, flock($probe, LOCK_EX | LOCK_NB));
                fclose($probe);
            }

            return array_map('file_get_contents', $paths);
        });

        $this->assertSame(['content-0', 'content-1', 'content-2', 'content-3', 'content-4'], $contents);
    }

    public static function chunkingProvider(): array
    {
        return [
            'batch, chunked' => ['batch', 2, false],
            'batch, unchunked' => ['batch', -1, true],
            'batch, within one chunk' => ['batch', 5, true],
            'batchOnce, chunked' => ['batchOnce', 2, false],
            'batchOnce, unchunked' => ['batchOnce', -1, true],
        ];
    }

    public function testHttpRetryOnServerError()
    {
        $file = new GenericFile('https://files/image.jpg');
        $hash = hash('sha256', 'https://files/image.jpg');
        $cachedPath = "{$this->cachePath}/{$hash}";

        // First request fails with 500, second succeeds
        $mock = new MockHandler([
            new Response(500, [], 'Server Error'),
            new Response(200, [], file_get_contents(__DIR__.'/files/test-image.jpg')),
        ]);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'http_retries' => 1,
            'http_retry_delay' => 10, // 10ms
        ], new Client(['handler' => HandlerStack::create($mock)]));

        $path = $cache->get($file, $this->noop);
        $this->assertEquals($cachedPath, $path);
        $this->assertFileExists($cachedPath);
    }

    public function testHttpRetryOnConnectException()
    {
        $url = 'https://files/image.jpg';
        $file = new GenericFile($url);
        $hash = hash('sha256', $url);
        $cachedPath = "{$this->cachePath}/{$hash}";
        $request = new Request('GET', $url);
        $connectException = new ConnectException('Temporary network error', $request);

        $mock = new MockHandler([
            $connectException,
            new Response(200, [], file_get_contents(__DIR__.'/files/test-image.jpg')),
        ]);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'http_retries' => 1,
            'http_retry_delay' => 10,
        ], new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]));

        $path = $cache->get($file, $this->noop);
        $this->assertEquals($cachedPath, $path);
        $this->assertFileExists($cachedPath);
    }

    public function testHttpNoRetryOnClientError()
    {
        $file = new GenericFile('https://files/image.jpg');

        // 404 should not be retried - using Response (not RequestException) to test new status code handling
        $mock = new MockHandler([
            new Response(404, [], 'Not Found'),
        ]);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'http_retries' => 3,
        ], new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]));

        try {
            $cache->get($file, $this->noop);
            $this->fail('Expected FailedToRetrieveFileException was not thrown');
        } catch (FailedToRetrieveFileException $e) {
            $this->assertStringContainsString('status code 404', $e->getMessage());
            $this->assertEquals(404, $e->statusCode);
        }

        $this->assertEquals(1, $cache->metrics()->errors);
    }

    public function testHttpRetryOn429RateLimit()
    {
        $file = new GenericFile('https://files/image.jpg');
        $hash = hash('sha256', 'https://files/image.jpg');
        $cachedPath = "{$this->cachePath}/{$hash}";

        // 429 should be retried - using Response to test new status code handling
        $mock = new MockHandler([
            new Response(429, [], 'Rate limited'),
            new Response(200, [], file_get_contents(__DIR__.'/files/test-image.jpg')),
        ]);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'http_retries' => 1,
            'http_retry_delay' => 10,
        ], new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]));

        $path = $cache->get($file, $this->noop);
        $this->assertEquals($cachedPath, $path);
    }

    public function testHttpRetryOnTransferFailureAfterHeaders()
    {
        $url = 'https://files/image.jpg';

        // The transfer broke after the 200 headers arrived (e.g. connection
        // reset mid-body): a network error, even though a response exists.
        $mock = new MockHandler([
            RequestException::create(new Request('GET', $url), new Response(200)),
            new Response(200, [], 'payload'),
        ]);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'http_retries' => 1,
            'http_retry_delay' => 1,
        ], new Client(['handler' => HandlerStack::create($mock)]));

        $this->assertSame('payload', $cache->get(new GenericFile($url), fn ($file, $path) => file_get_contents($path)));
        $this->assertSame(0, $mock->count());
    }

    public function testHttpNoRetryOnTooManyRedirects()
    {
        $redirects = array_map(
            static fn (int $i): Response => new Response(302, ['Location' => "https://files/hop-{$i}"]),
            range(1, 6)
        );
        $mock = new MockHandler($redirects);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'max_redirects' => 1,
            'http_retries' => 2,
            'http_retry_delay' => 1,
        ], new Client(['handler' => HandlerStack::create($mock)]));

        try {
            $cache->get(new GenericFile('https://files/image.jpg'));
            $this->fail('Expected TooManyRedirectsException was not thrown');
        } catch (TooManyRedirectsException) {
            // An exhausted redirect budget fails the same way on every attempt.
        }

        $this->assertSame(4, $mock->count(), 'Only the first attempt (request + 1 redirect) may be sent.');
    }

    public function testConfigValidationThrowsOnInvalidMaxSize()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('max_size');

        new FileStash([
            'path' => $this->cachePath,
            'max_size' => -1, // Invalid: must be 0 or positive
        ]);
    }

    public function testConfigValidationThrowsOnInvalidLockWaitTimeout()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('lock_wait_timeout');

        new FileStash([
            'path' => $this->cachePath,
            'lock_wait_timeout' => -2, // Invalid: must be -1 or non-negative
        ]);
    }

    public function testConfigValidationThrowsOnInvalidTimeout()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('timeout');

        new FileStash([
            'path' => $this->cachePath,
            'timeout' => -2, // Invalid: must be -1 or non-negative
        ]);
    }

    public function testConfigValidationThrowsOnInvalidConnectTimeout()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('connect_timeout');

        new FileStash([
            'path' => $this->cachePath,
            'connect_timeout' => -2, // Invalid: must be -1 or non-negative
        ]);
    }

    public function testConfigValidationThrowsOnInvalidReadTimeout()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('read_timeout');

        new FileStash([
            'path' => $this->cachePath,
            'read_timeout' => -2, // Invalid: must be -1 or non-negative
        ]);
    }

    public function testConfigValidationThrowsOnInvalidPruneTimeout()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('prune_timeout');

        new FileStash([
            'path' => $this->cachePath,
            'prune_timeout' => -2, // Invalid: must be -1 or non-negative
        ]);
    }

    public function testConfigValidationThrowsOnInvalidLifecycleLockTimeout()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('lifecycle_lock_timeout');

        new FileStash([
            'path' => $this->cachePath,
            'lifecycle_lock_timeout' => -2, // Invalid: must be -1 or non-negative
        ]);
    }

    public function testConfigValidationThrowsOnInvalidBatchChunkSize()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('batch_chunk_size');

        new FileStash([
            'path' => $this->cachePath,
            'batch_chunk_size' => 0, // Invalid: must be -1 or positive
        ]);
    }

    public function testLifecycleLockLivesOnlyInTheCacheDirectory()
    {
        $tempLocks = sys_get_temp_dir().'/laravel-file-stash/locks';
        $before = glob("{$tempLocks}/*") ?: [];

        // A trailing slash must address the same lock file.
        $cache = new FileStash(['path' => $this->cachePath.'/']);
        $cache->batch([new GenericFile('fixtures://test-file.txt')]);
        $cache->clear();

        $this->assertFileExists("{$this->cachePath}/.lifecycle.lock");
        $this->assertSame($before, glob("{$tempLocks}/*") ?: []);
    }

    public function testAllowedDisksRestrictsStorageDiskUrls()
    {
        $this->app['files']->put("{$this->diskPath}/secret.txt", 'secret');
        $cache = $this->createCache(['allowed_disks' => ['fixtures']]);

        $this->assertSame(
            file_get_contents(__DIR__.'/files/test-file.txt'),
            $cache->get(new GenericFile('fixtures://test-file.txt'), fn ($file, $path) => file_get_contents($path))
        );
        $this->assertTrue($cache->exists(new GenericFile('fixtures://test-file.txt')));

        foreach (['get', 'exists'] as $method) {
            try {
                $cache->{$method}(new GenericFile('test://secret.txt'));
                $this->fail("{$method}() read a disk that is not allowed.");
            } catch (DiskNotAllowedException $exception) {
                $this->assertSame('test', $exception->disk);
                // Existing catch (HostNotAllowedException) blocks keep working.
                $this->assertInstanceOf(HostNotAllowedException::class, $exception);
            }
        }

        $this->assertFileDoesNotExist($this->getCachedPath('test://secret.txt'));
    }

    public function testEmptyAllowedDisksBlocksAllDisksButNotHttp()
    {
        $cache = $this->createCacheWithMockClient([new Response(200, [], 'remote')], ['allowed_disks' => []]);

        $this->assertSame('remote', $cache->get(new GenericFile('https://example.com/a.txt'), fn ($file, $path) => file_get_contents($path)));

        $this->expectException(DiskNotAllowedException::class);
        $cache->get(new GenericFile('fixtures://test-file.txt'));
    }

    public function testGetRemoteWithAllowedHostsValidation()
    {
        $file = new GenericFile('https://allowed.example.com/image.jpg');

        $mock = new MockHandler([
            new Response(200, [], file_get_contents(__DIR__.'/files/test-image.jpg')),
        ]);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'allowed_hosts' => ['allowed.example.com'],
        ], new Client(['handler' => HandlerStack::create($mock)]));

        $path = $cache->get($file, $this->noop);
        $this->assertFileExists($path);
    }

    public function testGetRemoteBlocksDisallowedHost()
    {
        $file = new GenericFile('https://blocked.example.com/image.jpg');

        $mock = new MockHandler([
            new Response(200, [], file_get_contents(__DIR__.'/files/test-image.jpg')),
        ]);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'allowed_hosts' => ['allowed.example.com'],
        ], new Client(['handler' => HandlerStack::create($mock)]));

        $this->expectException(HostNotAllowedException::class);
        $cache->get($file, $this->noop);
    }

    public function testExistsRemoteBlocksDisallowedHost()
    {
        $file = new GenericFile('https://blocked.example.com/image.jpg');

        $mock = new MockHandler([
            new Response(200),
        ]);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'allowed_hosts' => ['allowed.example.com'],
        ], new Client(['handler' => HandlerStack::create($mock)]));

        $this->expectException(HostNotAllowedException::class);
        $cache->exists($file);
    }

    public function testExistsRemoteMimeTypeWithCharset()
    {
        // MIME type with charset should be handled correctly
        $mock = new MockHandler([
            new Response(200, ['content-type' => 'text/plain; charset=utf-8']),
        ]);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'mime_types' => ['text/plain'],
        ], new Client(['handler' => HandlerStack::create($mock)]));

        $file = new GenericFile('https://example.com/file.txt');
        $this->assertTrue($cache->exists($file));
    }

    public function testBatchOnceDeletesFilesAfterCallback()
    {
        $file = new GenericFile('fixtures://test-image.jpg');
        $hash = hash('sha256', 'fixtures://test-image.jpg');
        $cachedPath = "{$this->cachePath}/{$hash}";

        $cache = new FileStash(['path' => $this->cachePath]);

        $pathInCallback = null;
        $cache->batchOnce([$file], function ($files, $paths) use (&$pathInCallback) {
            $pathInCallback = $paths[0];
            $this->assertFileExists($paths[0]);

            return $paths;
        });

        $this->assertNotNull($pathInCallback);
        $this->assertFileDoesNotExist($cachedPath);
    }

    public function testBatchOnceDeletesFilesAfterCallbackException()
    {
        $file = new GenericFile('fixtures://test-image.jpg');
        $hash = hash('sha256', 'fixtures://test-image.jpg');
        $cachedPath = "{$this->cachePath}/{$hash}";

        $cache = new FileStash(['path' => $this->cachePath]);

        try {
            $cache->batchOnce([$file], function ($files, $paths) {
                $this->assertFileExists($paths[0]);
                throw new \RuntimeException('Callback failed');
            });
            $this->fail('Expected RuntimeException to be thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Callback failed', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($cachedPath);
    }

    public function testBatchOncePreservesPrimaryExceptionWhenCleanupFails()
    {
        $file = new GenericFile('fixtures://test-image.jpg');
        $cache = new class(['path' => $this->cachePath]) extends FileStash
        {
            protected function deleteUnusedEntries(array $paths, string $reason): array
            {
                throw new \RuntimeException('cleanup failed');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('primary callback failure');

        $cache->batchOnce([$file], function () {
            throw new \RuntimeException('primary callback failure');
        });
    }

    public function testBatchOnceCleanupTimeoutAfterSuccessfulCallbackReturnsResult()
    {
        $logger = new RecordingLogger;
        $cache = new FileStash(
            ['path' => $this->cachePath, 'lifecycle_lock_timeout' => 0.05],
            null,
            null,
            null,
            $logger
        );
        $file = new GenericFile('fixtures://test-file.txt');
        $foreignLock = null;

        try {
            $result = $cache->getOnce($file, function ($file, $path) use (&$foreignLock) {
                // A chunked batch of another process starts while the
                // callback runs, so the cleanup cannot take the pin lock.
                $foreignLock = fopen("{$this->cachePath}/.pin.lock", 'c+');
                $this->assertTrue(flock($foreignLock, LOCK_SH));

                return file_get_contents($path);
            });
        } finally {
            if (is_resource($foreignLock)) {
                fclose($foreignLock);
            }
        }

        // The work is done: the result is returned and the entry is left
        // for prune() instead of failing the caller.
        $this->assertSame(file_get_contents(__DIR__.'/files/test-file.txt'), $result);
        $this->assertFileExists($this->getCachedPath('fixtures://test-file.txt'));
        $this->assertCount(1, $logger->messages('warning'));
    }

    public function testBatchOnceThrowOnLock()
    {
        $cache = $this->createCache();
        $url = 'abc://some/image.jpg';
        $file = new GenericFile($url);
        $path = $this->getCachedPath($url);
        touch($path, time() - 1);

        $handle = fopen($path, 'w');
        flock($handle, LOCK_EX);

        try {
            $this->expectException(FileLockedException::class);
            $cache->batchOnce([$file], fn ($file, $path) => $file, true);
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    public function testPruneOnNonExistentPath()
    {
        $nonExistentPath = sys_get_temp_dir().'/non_existent_path_'.bin2hex(random_bytes(8));
        $cache = new FileStash(['path' => $nonExistentPath]);

        $this->assertSame(['deleted' => 0, 'remaining' => 0, 'total_size' => 0, 'completed' => true], $cache->prune());
    }

    public function testClearOnNonExistentPath()
    {
        $nonExistentPath = sys_get_temp_dir().'/non_existent_path_'.bin2hex(random_bytes(8));
        $cache = new FileStash(['path' => $nonExistentPath]);

        $this->assertDirectoryDoesNotExist($nonExistentPath);
        $cache->clear();
        $this->assertDirectoryDoesNotExist($nonExistentPath);
    }

    public function testRetrieveCreatesPathIfNotExists()
    {
        $newPath = sys_get_temp_dir().'/new_cache_path_'.bin2hex(random_bytes(8));
        $this->assertDirectoryDoesNotExist($newPath);

        $file = new GenericFile('fixtures://test-file.txt');
        $cache = new FileStash(['path' => $newPath]);

        try {
            $cache->get($file, $this->noop);
            $this->assertDirectoryExists($newPath);
        } finally {
            $this->app['files']->deleteDirectory($newPath);
        }
    }

    public function testGetDefaultCallback()
    {
        $file = new GenericFile('fixtures://test-file.txt');
        $cache = new FileStash(['path' => $this->cachePath]);

        // get() without callback should return path
        $result = $cache->get($file);
        $this->assertIsString($result);
        $this->assertFileExists($result);
    }

    public function testBatchDefaultCallback()
    {
        $this->app['files']->put("{$this->diskPath}/test-image.jpg", 'abc');
        $file = new GenericFile('test://test-image.jpg');

        $cache = new FileStash(['path' => $this->cachePath]);

        // batch() without callback should return array of paths
        $result = $cache->batch([$file]);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertFileExists($result[0]);
    }

    public function testGetOnceDefaultCallback()
    {
        $file = new GenericFile('fixtures://test-file.txt');
        $cache = new FileStash(['path' => $this->cachePath]);

        // getOnce() without callback should return path (and delete file)
        $result = $cache->getOnce($file);
        $this->assertIsString($result);
    }

    public function testBatchOnceDefaultCallback()
    {
        $file = new GenericFile('fixtures://test-file.txt');
        $cache = new FileStash(['path' => $this->cachePath]);

        // batchOnce() without callback should return array of paths
        $result = $cache->batchOnce([$file]);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testUnlimitedFileSize()
    {
        $this->app['files']->put("{$this->diskPath}/large-file.txt", str_repeat('x', 10000));
        $file = new GenericFile('test://large-file.txt');

        $cache = new FileStash([
            'path' => $this->cachePath,
            'max_file_size' => -1, // Unlimited
        ]);

        $path = $cache->get($file, $this->noop);
        $this->assertFileExists($path);
    }

    public function testExistsDiskUnlimitedFileSize()
    {
        $this->app['files']->put("{$this->diskPath}/large-file.txt", str_repeat('x', 10000));
        $file = new GenericFile('test://large-file.txt');

        $cache = new FileStash([
            'path' => $this->cachePath,
            'max_file_size' => -1, // Unlimited
        ]);

        $this->assertTrue($cache->exists($file));
    }

    public function testExistsRemoteUnlimitedFileSize()
    {
        $mock = new MockHandler([
            new Response(200, ['content-length' => '1000000']), // 1MB
        ]);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'max_file_size' => -1, // Unlimited
        ], new Client(['handler' => HandlerStack::create($mock)]));

        $file = new GenericFile('https://example.com/large-file.zip');
        $this->assertTrue($cache->exists($file));
    }

    public function testGenericFileRejectsInvalidUrl()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('protocol');
        new GenericFile('invalid-url-without-protocol');
    }

    public function testGenericFileRejectsEmptyPath()
    {
        // 'mydisk://' would otherwise probe the disk root directory.
        $this->expectException(\InvalidArgumentException::class);
        new GenericFile('mydisk://');
    }

    public function testGenericFileRejectsEmptyScheme()
    {
        $this->expectException(\InvalidArgumentException::class);
        new GenericFile('://path/file.jpg');
    }

    public function testForgetOnColdCacheDoesNotCreateDirectory()
    {
        $coldPath = sys_get_temp_dir().'/file_stash_cold_'.bin2hex(random_bytes(8));
        $cache = new FileStash(['path' => $coldPath]);

        $this->assertFalse($cache->forget(new GenericFile('https://example.com/file.jpg')));
        $this->assertDirectoryDoesNotExist($coldPath);
    }

    public function testGetDiskFileNotFound()
    {
        config(['filesystems.disks.test' => ['driver' => 'local', 'root' => $this->diskPath]]);
        $file = new GenericFile('test://non-existent-file.txt');

        $cache = new FileStash(['path' => $this->cachePath]);

        $this->expectException(FileNotFoundException::class);
        $cache->get($file, $this->noop);
    }

    public function testPruneBySizeDeletesOldestFiles()
    {
        // Create files with different access times
        $this->app['files']->put($this->getCachedPath('old'), str_repeat('a', 100));
        touch($this->getCachedPath('old'), time() - 10, time() - 10);

        $this->app['files']->put($this->getCachedPath('new'), str_repeat('b', 100));
        // new file has current atime

        clearstatcache();

        $cache = new FileStash([
            'path' => $this->cachePath,
            'max_size' => 100, // Only allow 100 bytes
            'max_age' => 60, // Don't prune by age
        ]);

        $cache->prune();

        // Old file should be deleted, new file should remain
        $this->assertFileDoesNotExist($this->getCachedPath('old'));
        $this->assertFileExists($this->getCachedPath('new'));
    }

    // =========================================================================
    // Phase 1 Tests
    // =========================================================================

    public function testPruneReturnsCompletedTrue()
    {
        $cache = $this->createCache();
        $stats = $cache->prune();
        $this->assertTrue($stats['completed']);
    }

    public function testPruneReturnsCompletedFalseOnTimeout()
    {
        // Create many expired files so prune has work to do
        for ($i = 0; $i < 5; $i++) {
            $this->app['files']->put($this->getCachedPath("file{$i}"), str_repeat('x', 100));
            touch($this->getCachedPath("file{$i}"), time() - 7200); // 2 hours old
        }

        // Use a subclass that forces isPruneTimedOut to return true after first file
        $cache = new class(['path' => $this->cachePath, 'max_age' => 1, 'prune_timeout' => 300]) extends FileStash
        {
            private int $pruneCheckCount = 0;

            protected function isPruneTimedOut(int $startTime, int $timeout, string $phase, ?int $remainingSize = null): bool
            {
                $this->pruneCheckCount++;

                // Time out after the first check during age-based pruning
                return $this->pruneCheckCount > 1 && $phase !== 'file collection';
            }
        };

        $stats = $cache->prune();
        $this->assertArrayHasKey('completed', $stats);
        $this->assertFalse($stats['completed']);

        // Even on timeout the stats must reflect the collected files instead
        // of returning zeros: nothing was deleted, 5 files of 100 bytes remain.
        $this->assertEquals(0, $stats['deleted']);
        $this->assertEquals(5, $stats['remaining']);
        $this->assertEquals(500, $stats['total_size']);
    }

    public function testGuzzleClientHasUserAgent()
    {
        $cache = $this->createCache(['user_agent' => 'TestAgent/1.0']);
        $config = $this->getClientConfig($cache);

        $this->assertArrayHasKey('headers', $config);
        $this->assertEquals('TestAgent/1.0', $config['headers']['User-Agent']);
    }

    public function testRequestsHaveMaxRedirects()
    {
        $options = $this->captureRequestOptions(['max_redirects' => 3]);

        $this->assertSame(3, $options['allow_redirects']['max']);
        $this->assertIsCallable($options['allow_redirects']['on_redirect']);
    }

    public function testAllowedHostsEmptyStringAllowsAllHosts()
    {
        // Empty string from env var should be treated as null (allow all hosts)
        $cache = $this->createCacheWithMockClient([
            new Response(200, [], $this->getTestImageContent()),
        ], ['allowed_hosts' => '']);

        $file = new GenericFile('https://any-host.example.com/image.jpg');
        // Should not throw HostNotAllowedException
        $path = $cache->get($file, $this->noop);
        $this->assertFileEquals(__DIR__.'/files/test-image.jpg', $path);
    }

    /**
     * The copy limit is max_file_size + 1 (one byte over proves "too
     * large"); at PHP_INT_MAX that would overflow into a float.
     */
    #[DataProvider('sourceProvider')]
    public function testMaxFileSizeOfPhpIntMaxDoesNotOverflow(string $url)
    {
        $cache = $this->createCacheWithMockClient([
            new Response(200, [], $this->getTestImageContent()),
        ], ['max_file_size' => PHP_INT_MAX]);

        $path = $cache->get(new GenericFile($url));

        $this->assertFileEquals(__DIR__.'/files/test-image.jpg', $path);
    }

    public static function sourceProvider(): array
    {
        return [
            'storage disk' => ['fixtures://test-image.jpg'],
            'http' => ['https://example.com/image.jpg'],
        ];
    }

    // =========================================================================
    // Phase 2 Tests - Events
    // =========================================================================

    public function testEventsDispatchedWhenEnabled()
    {
        $dispatched = [];
        $dispatcher = $this->createStub(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $cache = new FileStash(
            ['path' => $this->cachePath, 'events_enabled' => true],
            null,
            null,
            null,
            null,
            $dispatcher
        );

        $file = new GenericFile('fixtures://test-image.jpg');
        $cache->get($file, $this->noop);

        // First access is a miss + retrieval
        $eventTypes = array_map('get_class', $dispatched);
        $this->assertContains(CacheMiss::class, $eventTypes);
        $this->assertContains(CacheFileRetrieved::class, $eventTypes);

        // Second access should be a hit
        $dispatched = [];
        $cache->get($file, $this->noop);
        $eventTypes = array_map('get_class', $dispatched);
        $this->assertContains(CacheHit::class, $eventTypes);
    }

    public function testEventsNotDispatchedWhenDisabled()
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $cache = new FileStash(
            ['path' => $this->cachePath, 'events_enabled' => false],
            null,
            null,
            null,
            null,
            $dispatcher
        );

        $file = new GenericFile('fixtures://test-image.jpg');
        $cache->get($file, $this->noop);
    }

    public function testPruneCompletedEventDispatched()
    {
        $dispatched = [];
        $dispatcher = $this->createStub(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $cache = new FileStash(
            ['path' => $this->cachePath, 'events_enabled' => true],
            null,
            null,
            null,
            null,
            $dispatcher
        );

        $cache->prune();

        $pruneEvents = array_filter($dispatched, fn ($e) => $e instanceof CachePruneCompleted);
        $this->assertCount(1, $pruneEvents);
        $pruneEvent = array_values($pruneEvents)[0];
        $this->assertTrue($pruneEvent->completed);
    }

    public function testEvictionEventDispatchedOnClear()
    {
        $dispatched = [];
        $dispatcher = $this->createStub(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $cache = new FileStash(
            ['path' => $this->cachePath, 'events_enabled' => true],
            null,
            null,
            null,
            null,
            $dispatcher
        );

        // Add a file to cache, then clear
        $this->app['files']->put($this->getCachedPath('testfile'), 'content');
        $cache->clear();

        $evictionEvents = array_values(array_filter($dispatched, fn ($e) => $e instanceof CacheFileEvicted));
        $this->assertCount(1, $evictionEvents);
        $this->assertSame('cleared', $evictionEvents[0]->reason);
        $this->assertSame($this->getCachedPath('testfile'), $evictionEvents[0]->path);
    }

    // =========================================================================
    // Phase 2 Tests - forget()
    // =========================================================================

    public function testForgetDeletesCachedFile()
    {
        $cache = $this->createCache();
        $file = new GenericFile('fixtures://test-image.jpg');

        // First, cache the file
        $cache->get($file, $this->noop);
        $cachedPath = $this->getCachedPath('fixtures://test-image.jpg');
        $this->assertFileExists($cachedPath);

        // Now forget it
        $result = $cache->forget($file);
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($cachedPath);
    }

    public function testForgetReturnsFalseForNonExistentFile()
    {
        $cache = $this->createCache();
        $file = new GenericFile('fixtures://nonexistent.jpg');

        $result = $cache->forget($file);
        $this->assertFalse($result);
    }

    public function testForgetReturnsFalseForLockedFile()
    {
        $cache = $this->createCache();
        $file = new GenericFile('fixtures://test-image.jpg');

        // Cache the file
        $cache->get($file, $this->noop);
        $cachedPath = $this->getCachedPath('fixtures://test-image.jpg');

        // Lock the file
        $handle = fopen($cachedPath, 'rb');
        flock($handle, LOCK_SH);

        try {
            $result = $cache->forget($file);
            $this->assertFalse($result);
            $this->assertFileExists($cachedPath);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testFileStashFakeForget()
    {
        $fake = new FileStashFake($this->app);
        $file = new GenericFile('https://example.com/image.jpg');

        // Non-existent file
        $this->assertFalse($fake->forget($file));
    }

    // =========================================================================
    // Phase 2 Tests - Metrics
    // =========================================================================

    public function testMetricsIncrementCorrectly()
    {
        $cache = $this->createCache();
        $file = new GenericFile('fixtures://test-image.jpg');

        $metrics = $cache->metrics();
        $this->assertInstanceOf(CacheMetrics::class, $metrics);
        $this->assertEquals(0, $metrics->hits);
        $this->assertEquals(0, $metrics->misses);
        $this->assertNull($metrics->hitRate());

        // First access = miss
        $cache->get($file, $this->noop);
        $this->assertEquals(0, $metrics->hits);
        $this->assertEquals(1, $metrics->misses);
        $this->assertEquals(1, $metrics->retrievals);
        $this->assertEquals(0.0, $metrics->hitRate());

        // Second access = hit
        $cache->get($file, $this->noop);
        $this->assertEquals(1, $metrics->hits);
        $this->assertEquals(1, $metrics->misses);
        $this->assertEquals(50.0, $metrics->hitRate());
    }

    public function testMetricsEvictionOnForget()
    {
        $cache = $this->createCache();
        $file = new GenericFile('fixtures://test-image.jpg');
        $cache->get($file, $this->noop);

        $this->assertEquals(0, $cache->metrics()->evictions);
        $cache->forget($file);
        $this->assertEquals(1, $cache->metrics()->evictions);
    }

    public function testGetSucceedsWithZeroLockWaitTimeout()
    {
        // Regression: flock success must be checked before the deadline, so an
        // uncontended read succeeds even with lock_wait_timeout=0.
        $cache = $this->createCache(['lock_wait_timeout' => 0]);
        $file = new GenericFile('fixtures://test-image.jpg');

        $path = $cache->get($file, $this->noop);
        $this->assertFileExists($path);

        // Second call must read the already-cached (unlocked) file.
        $path = $cache->get($file, $this->noop);
        $this->assertFileExists($path);
        $this->assertEquals(1, $cache->metrics()->hits);
    }

    public function testMetricsErrorsIncrementOnMimeTypeRejection()
    {
        // Regression: errors must count every failed retrieval, not only
        // exhausted lock attempts.
        $cache = $this->createCache(['mime_types' => ['image/jpeg']]);
        $file = new GenericFile('fixtures://test-file.txt');

        try {
            $cache->get($file, $this->noop);
            $this->fail('Expected MimeTypeIsNotAllowedException was not thrown');
        } catch (MimeTypeIsNotAllowedException $e) {
            // expected
        }

        $this->assertEquals(1, $cache->metrics()->errors);
    }

    public function testMetricsReset()
    {
        $metrics = new CacheMetrics;
        $metrics->hits = 5;
        $metrics->misses = 3;
        $metrics->evictions = 2;
        $metrics->retrievals = 3;
        $metrics->errors = 1;

        $array = $metrics->toArray();
        $this->assertEquals(5, $array['hits']);
        $this->assertEquals(3, $array['misses']);
        $this->assertNotNull($array['hit_rate']);

        $metrics->reset();
        $this->assertEquals(0, $metrics->hits);
        $this->assertEquals(0, $metrics->misses);
        $this->assertNull($metrics->hitRate());
    }

    // =========================================================================
    // Phase 3 Tests - Touch Throttling
    // =========================================================================

    public function testTouchSkippedWhenWithinInterval()
    {
        $cache = $this->createCache(['touch_interval' => 300]);
        $url = 'fixtures://test-image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        // Pre-populate cache
        copy(__DIR__.'/files/test-image.jpg', $cachedPath);
        // Set atime to 5 seconds ago (still within 300s touch_interval)
        touch($cachedPath, time(), time() - 5);
        clearstatcache();
        $atimeBefore = fileatime($cachedPath);

        $cache->get($file, $this->noop);

        clearstatcache();
        $atimeAfter = fileatime($cachedPath);

        // atime should NOT have changed because it's within the interval
        $this->assertEquals($atimeBefore, $atimeAfter);
    }

    public function testTouchCalledWhenIntervalExceeded()
    {
        $cache = $this->createCache(['touch_interval' => 1]);
        $url = 'fixtures://test-image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        // Pre-populate cache with old atime
        copy(__DIR__.'/files/test-image.jpg', $cachedPath);
        touch($cachedPath, time() - 10);
        clearstatcache();
        $atimeBefore = fileatime($cachedPath);

        $cache->get($file, $this->noop);

        clearstatcache();
        $atimeAfter = fileatime($cachedPath);

        // atime should have changed because interval was exceeded
        $this->assertNotEquals($atimeBefore, $atimeAfter);
    }

    public function testEntryIsNamedAfterTheSha256OfTheUrl()
    {
        // Layout invariant: prune()/clear() only touch names of this shape,
        // and workers of different processes must agree on it.
        $cache = $this->createCache();

        $path = $cache->get(new GenericFile('fixtures://test-file.txt'));

        $this->assertSame($this->cachePath.'/'.hash('sha256', 'fixtures://test-file.txt'), $path);
        $this->assertSame($path, $cache->get(new GenericFile('fixtures://test-file.txt')));
        $this->assertNotSame($path, $cache->get(new GenericFile('fixtures://test-image.jpg')));
    }

    // =========================================================================
    // Phase 4 Tests - URL Encoding
    // =========================================================================

    // =========================================================================
    // Phase 4 Tests - Structured Exceptions
    // =========================================================================

    public function testFileIsTooLargeExceptionHasMaxBytes()
    {
        $exception = FileIsTooLargeException::create(1024);
        $this->assertEquals(1024, $exception->maxBytes);
        $this->assertStringContainsString('1024', $exception->getMessage());
    }

    public function testHostNotAllowedExceptionHasHost()
    {
        $exception = HostNotAllowedException::create('evil.com');
        $this->assertEquals('evil.com', $exception->host);
        $this->assertStringContainsString('evil.com', $exception->getMessage());
    }

    public function testMimeTypeIsNotAllowedExceptionHasMimeType()
    {
        $exception = MimeTypeIsNotAllowedException::create('text/html');
        $this->assertEquals('text/html', $exception->mimeType);
        $this->assertStringContainsString('text/html', $exception->getMessage());
    }

    public function testInvalidConfigurationExceptionHasKeyAndReason()
    {
        $exception = InvalidConfigurationException::create('max_age', 'must be at least 1 minute');
        $this->assertEquals('max_age', $exception->key);
        $this->assertEquals('must be at least 1 minute', $exception->reason);
        $this->assertStringContainsString('max_age', $exception->getMessage());
    }

    public function testExceptionReadonlyPropertiesSafeWithDirectConstruction()
    {
        // Verify exceptions are safe when constructed directly (without create())
        $e1 = new FileIsTooLargeException('test');
        $this->assertEquals(0, $e1->maxBytes);

        $e2 = new HostNotAllowedException('test');
        $this->assertEquals('', $e2->host);

        $e3 = new MimeTypeIsNotAllowedException('test');
        $this->assertEquals('', $e3->mimeType);

        $e4 = new InvalidConfigurationException('test');
        $this->assertEquals('', $e4->key);
        $this->assertEquals('', $e4->reason);
    }

    public function testPruneEvictionEventDispatched()
    {
        $dispatched = [];
        $dispatcher = $this->createStub(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        // Create an expired file
        $this->app['files']->put($this->getCachedPath('expired'), 'content');
        touch($this->getCachedPath('expired'), time() - 7200);

        $cache = new FileStash(
            ['path' => $this->cachePath, 'max_age' => 1, 'events_enabled' => true],
            null,
            null,
            null,
            null,
            $dispatcher
        );

        $cache->prune();

        $evictions = array_values(array_filter($dispatched, fn ($e) => $e instanceof CacheFileEvicted));
        $this->assertCount(1, $evictions);
        $this->assertSame('pruned_age', $evictions[0]->reason);
        $this->assertSame($this->getCachedPath('expired'), $evictions[0]->path);
    }

    public function testForgetEvictionEventDispatched()
    {
        $dispatched = [];
        $dispatcher = $this->createStub(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $cache = new FileStash(
            ['path' => $this->cachePath, 'events_enabled' => true],
            null,
            null,
            null,
            null,
            $dispatcher
        );

        $file = new GenericFile('fixtures://test-image.jpg');
        $cache->get($file, $this->noop);

        $dispatched = []; // reset
        $cache->forget($file);

        $evictions = array_filter($dispatched, fn ($e) => $e instanceof CacheFileEvicted);
        $this->assertCount(1, $evictions);
    }

    public function testFileStashFakeMetrics()
    {
        $fake = new FileStashFake($this->app);
        $metrics = $fake->metrics();
        $this->assertInstanceOf(CacheMetrics::class, $metrics);
        $this->assertEquals(0, $metrics->hits);
    }

    public function testFakeCreatesRealFiles()
    {
        $fake = new FileStashFake($this->app);
        $file = new GenericFile('https://example.com/image.jpg');

        $content = $fake->get($file, fn ($file, $path) => file_get_contents($path));

        $this->assertSame('fake-content:https://example.com/image.jpg', $content);

        // Second retrieval is a hit
        $fake->get($file);
        $this->assertEquals(1, $fake->metrics()->hits);
        $this->assertEquals(1, $fake->metrics()->misses);
    }

    public function testFakePutFakeContent()
    {
        $fake = new FileStashFake($this->app);
        $fake->putFake('https://example.com/data.csv', "a,b\n1,2\n");
        $file = new GenericFile('https://example.com/data.csv');

        $content = $fake->get($file, fn ($file, $path) => file_get_contents($path));

        $this->assertSame("a,b\n1,2\n", $content);
    }

    public function testFakeRetrievalAssertions()
    {
        $fake = new FileStashFake($this->app);

        $fake->assertNothingRetrieved();

        $fake->get(new GenericFile('https://example.com/a.jpg'));
        $fake->get(new GenericFile('https://example.com/a.jpg'));

        $fake->assertRetrieved('https://example.com/a.jpg');
        $fake->assertRetrievedTimes('https://example.com/a.jpg', 2);
        $fake->assertNotRetrieved('https://example.com/b.jpg');
    }

    public function testFakeForgetAssertion()
    {
        $fake = new FileStashFake($this->app);
        $file = new GenericFile('https://example.com/a.jpg');

        $fake->get($file);
        $this->assertTrue($fake->forget($file));
        $fake->assertForgotten('https://example.com/a.jpg');
        $this->assertEquals(1, $fake->metrics()->evictions);
    }

    public function testFakeGetOnceRemovesFile()
    {
        $fake = new FileStashFake($this->app);
        $file = new GenericFile('https://example.com/a.jpg');

        $path = $fake->getOnce($file);

        $this->assertFileDoesNotExist($path);
        $this->assertEquals(1, $fake->metrics()->evictions);
    }

    public function testFacadeReportsTheFakeAsFake()
    {
        $this->assertFalse(\Jackardios\FileStash\Facades\FileStash::isFake());

        \Jackardios\FileStash\Facades\FileStash::fake();

        $this->assertTrue(\Jackardios\FileStash\Facades\FileStash::isFake());
    }

    public function testFakePutFakeAfterRetrievalReplacesContent()
    {
        $fake = new FileStashFake($this->app);
        $file = new GenericFile('https://example.com/data.csv');
        $read = fn ($file, $path) => file_get_contents($path);

        $this->assertSame('fake-content:https://example.com/data.csv', $fake->get($file, $read));

        $fake->putFake('https://example.com/data.csv', 'updated');

        $this->assertSame('updated', $fake->get($file, $read));
    }

    public function testFakeForgetInsideBatchIsDeferredUntilTheBatchEnds()
    {
        // Mirrors the real cache: the entry stays readable for the rest of
        // the callback and is deleted once the outermost batch returns.
        $fake = new FileStashFake($this->app);
        $file = new GenericFile('https://example.com/a.jpg');

        $path = $fake->batch([$file], function ($files, $paths) use ($fake, $file) {
            $this->assertTrue($fake->forget($file));
            $this->assertFileExists($paths[0]);
            $this->assertSame(0, $fake->metrics()->evictions);

            return $paths[0];
        });

        $this->assertFileDoesNotExist($path);
        $this->assertSame(1, $fake->metrics()->evictions);
        $fake->assertForgotten('https://example.com/a.jpg');
    }

    public function testFakeGetOnceInsideBatchKeepsTheFileForTheOuterCallback()
    {
        $fake = new FileStashFake($this->app);
        $file = new GenericFile('https://example.com/a.jpg');

        $path = $fake->get($file, function ($file, $outerPath) use ($fake) {
            $fake->getOnce($file);
            $this->assertFileExists($outerPath);

            return $outerPath;
        });

        $this->assertFileDoesNotExist($path);
        $this->assertSame(1, $fake->metrics()->evictions);
    }

    public function testFakeDeferredDeletionsRunWhenTheCallbackThrows()
    {
        $fake = new FileStashFake($this->app);
        $file = new GenericFile('https://example.com/a.jpg');
        $path = $fake->get($file);

        try {
            $fake->batch([$file], function () use ($fake, $file) {
                $fake->forget($file);

                throw new \RuntimeException('callback failure');
            });
            $this->fail('Expected the callback exception to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('callback failure', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($path);
    }

    public function testFakeNeverTouchesStorageDisks()
    {
        // Hermetic like Http::fake(): an unconfigured disk would throw if the
        // fake resolved it, a configured one could be real cloud storage.
        $fake = new FileStashFake($this->app);
        $file = new GenericFile('not-configured://a.txt');

        $this->assertFalse($fake->exists($file));
        $this->assertSame('fake-content:not-configured://a.txt', $fake->get($file, fn ($file, $path) => file_get_contents($path)));
        $this->assertTrue($fake->exists($file));
    }

    public function testFakeShouldExist()
    {
        $fake = new FileStashFake($this->app);
        $existing = new GenericFile('https://example.com/there.jpg');
        $missing = new GenericFile('https://example.com/gone.jpg');

        // Unknown URLs do not exist by default
        $this->assertFalse($fake->exists($existing));

        $fake->shouldExist('https://example.com/there.jpg');
        $fake->shouldExist('https://example.com/gone.jpg', false);

        $this->assertTrue($fake->exists($existing));
        $this->assertFalse($fake->exists($missing));

        // Retrieved files exist by default
        $retrieved = new GenericFile('https://example.com/cached.jpg');
        $fake->get($retrieved);
        $this->assertTrue($fake->exists($retrieved));
    }

    public function testFacadeFakeReturnsAndSwapsInstance()
    {
        $fake = \Jackardios\FileStash\Facades\FileStash::fake();

        $this->assertInstanceOf(FileStashFake::class, $fake);
        $this->assertSame($fake, $this->app->make('file-stash'));
    }

    public function testFakeSatisfiesConcreteClassTypehint()
    {
        // The container aliases the concrete class to the swapped fake; a
        // fake not extending FileStash would blow up concrete typehints.
        \Jackardios\FileStash\Facades\FileStash::fake();

        $resolved = $this->app->call(static fn (FileStash $cache): FileStash => $cache);

        $this->assertInstanceOf(FileStashFake::class, $resolved);
    }

    public function testFakeUsesStableDirectoryWipedOnConstruction()
    {
        // Mirrors Storage::fake(): a stable directory, wiped per fake.
        $first = new FileStashFake($this->app);
        $path = $first->get(new GenericFile('https://example.com/a.jpg'));
        $this->assertFileExists($path);

        $second = new FileStashFake($this->app);

        $this->assertSame($first->path(), $second->path());
        $this->assertStringContainsString('framework/testing/disks/file-stash', $second->path());
        $this->assertFileDoesNotExist($path);
    }

    // =========================================================================
    // getDiskFile — null readStream
    // =========================================================================

    public function testGetDiskFileThrowsWhenReadStreamReturnsNull()
    {
        $filesystemMock = $this->createStub(FilesystemAdapter::class);
        $filesystemMock->method('readStream')->willReturn(null);
        $filesystemMock->method('getDriver')->willReturn($filesystemMock);
        $filesystemMock->method('get')->willReturn($filesystemMock);

        $filesystemManagerMock = $this->createStub(FilesystemManager::class);
        $filesystemManagerMock->method('disk')->willReturn($filesystemMock);

        $cache = new FileStash(
            ['path' => $this->cachePath],
            null,
            null,
            $filesystemManagerMock
        );

        $file = new GenericFile('s3://some/missing-file.jpg');

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('Could not open file stream');

        $cache->get($file, $this->noop);
    }

    // =========================================================================
    // makeHttpClient — timeout configuration
    // =========================================================================

    public function testRequestsHaveTimeoutSettings()
    {
        $options = $this->captureRequestOptions([
            'timeout' => 30,
            'connect_timeout' => 10,
            'read_timeout' => 15,
        ]);

        $this->assertEquals(30, $options['timeout']);
        $this->assertEquals(10, $options['connect_timeout']);

        // read_timeout maps to curl's low-speed abort (stall timeout)
        $this->assertSame(1, $options['curl'][CURLOPT_LOW_SPEED_LIMIT]);
        $this->assertSame(15, $options['curl'][CURLOPT_LOW_SPEED_TIME]);
    }

    public function testDefaultClientDoesNotThrowOnHttpErrors()
    {
        $this->assertFalse($this->getClientConfig($this->createCache())['http_errors']);
    }

    public function testRequestsRoundUpFractionalReadTimeoutForCurl()
    {
        $options = $this->captureRequestOptions(['read_timeout' => 0.5]);

        // Sub-second stall timeouts are rounded up to curl's 1-second minimum
        $this->assertSame(1, $options['curl'][CURLOPT_LOW_SPEED_TIME]);
    }

    public function testRequestsClampNegativeTimeoutsToZero()
    {
        $options = $this->captureRequestOptions([
            'timeout' => -1,
            'connect_timeout' => -1,
            'read_timeout' => -1,
        ]);

        $this->assertEquals(0, $options['timeout']);
        $this->assertEquals(0, $options['connect_timeout']);

        // read_timeout=-1 disables the curl low-speed abort entirely
        $this->assertArrayNotHasKey('curl', $options);
    }

    // =========================================================================
    // SSRF Redirect Protection Tests
    // =========================================================================

    public function testRedirectToAllowedHostIsFollowed()
    {
        $cache = $this->createCacheWithMockClient([
            new Response(302, ['Location' => 'https://cdn.example.com/final.jpg']),
            new Response(200, [], 'final body'),
        ], ['allowed_hosts' => ['example.com', '*.example.com']]);

        $content = $cache->get(new GenericFile('https://example.com/image.jpg'), fn ($file, $path) => file_get_contents($path));

        $this->assertSame('final body', $content);
    }

    // =========================================================================
    // Redirects — sink integrity and per-request options
    // =========================================================================

    public function testRedirectBodyDoesNotPolluteCachedFile()
    {
        // Guzzle reuses the sink across redirect hops and rewinds it between
        // them without truncating: a longer redirect body must not leave a
        // stale tail after the shorter final body.
        $finalBody = str_repeat('F', 100);
        $cache = $this->createCacheWithMockClient([
            new Response(301, ['Location' => 'https://files/final.jpg'], str_repeat('D', 300)),
            new Response(200, [], $finalBody),
        ]);

        $path = $cache->get(new GenericFile('https://files/image.jpg'), $this->noop);

        $this->assertSame($finalBody, file_get_contents($path));
    }

    public function testRedirectBodyDoesNotCountAgainstMaxFileSize()
    {
        // max_file_size sits above the final body but below the redirect
        // decoy body: the download only succeeds if the decoy is not counted.
        // MockHandler ignores short writes (the next hop's reset hides a
        // counted decoy), curl aborts on them — hence the curl-like handler.
        $finalBody = str_repeat('F', 100);
        $cache = new FileStash(['path' => $this->cachePath, 'max_file_size' => 150], new Client([
            'handler' => HandlerStack::create($this->curlLikeHandler([
                new Response(301, ['Location' => 'https://files/final.jpg'], str_repeat('D', 300)),
                new Response(200, [], $finalBody),
            ])),
        ]));

        $path = $cache->get(new GenericFile('https://files/image.jpg'), $this->noop);

        $this->assertSame($finalBody, file_get_contents($path));
    }

    public function testZeroMaxRedirectsRejectsRedirectResponse()
    {
        // With max_redirects=0 Guzzle returns the 3xx as the final response;
        // it must be rejected instead of being cached (empty or with the
        // redirect notice body).
        $url = 'https://files/image.jpg';
        $cache = $this->createCacheWithMockClient([
            new Response(301, ['Location' => 'https://files/final.jpg'], 'redirect notice body'),
        ], ['max_redirects' => 0]);

        try {
            $cache->get(new GenericFile($url), $this->noop);
            $this->fail('Expected FailedToRetrieveFileException to be thrown.');
        } catch (FailedToRetrieveFileException $exception) {
            $this->assertSame(301, $exception->statusCode);
        }

        $this->assertFileDoesNotExist($this->getCachedPath($url));
        $this->assertSame([], glob($this->cachePath.'/*.tmp') ?: []);
    }

    public function testInjectedClientReceivesSecurityOptionsPerRequest()
    {
        // Timeouts, the redirect budget and the on_redirect host validation
        // must apply per request, so an injected client configured without
        // them cannot silently disable any of it.
        $captured = [];
        $mock = new MockHandler([
            new Response(200, [], $this->getTestImageContent()),
            new Response(200),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $handler) use (&$captured) {
            return function (RequestInterface $request, array $options) use ($handler, &$captured) {
                $captured[] = ['method' => $request->getMethod(), 'options' => $options];

                return $handler($request, $options);
            };
        });

        $cache = new FileStash([
            'path' => $this->cachePath,
            'timeout' => 30,
            'connect_timeout' => 10,
            'read_timeout' => 15,
            'max_redirects' => 3,
        ], new Client(['handler' => $stack]));

        $file = new GenericFile('https://files/image.jpg');
        $cache->get($file, $this->noop);
        $cache->exists($file);

        $this->assertSame(['GET', 'HEAD'], array_column($captured, 'method'));

        foreach ($captured as $request) {
            $options = $request['options'];
            $this->assertEquals(30, $options['timeout']);
            $this->assertEquals(10, $options['connect_timeout']);
            $this->assertEquals(3, $options['allow_redirects']['max']);
            $this->assertIsCallable($options['allow_redirects']['on_redirect']);
            $this->assertSame(15, $options['curl'][CURLOPT_LOW_SPEED_TIME]);
        }
    }

    public function testInjectedClientCurlAndRedirectSettingsAreMergedNotReplaced()
    {
        // Guzzle shallow-merges request options over client options: without
        // an explicit merge, the per-request `curl` and `allow_redirects`
        // arrays would silently drop the injected client's own settings.
        $captured = [];
        $clientRedirects = [];
        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://files/final.jpg']),
            new Response(200, [], $this->getTestImageContent()),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $handler) use (&$captured) {
            return function (RequestInterface $request, array $options) use ($handler, &$captured) {
                $captured[] = $options;

                return $handler($request, $options);
            };
        });

        $cache = new FileStash([
            'path' => $this->cachePath,
            'read_timeout' => 15,
            'max_redirects' => 3,
            'allowed_hosts' => ['files'],
        ], new Client([
            'handler' => $stack,
            'curl' => [CURLOPT_LOW_SPEED_TIME => 99, CURLOPT_SSL_VERIFYSTATUS => true],
            'allow_redirects' => [
                'max' => 10,
                'protocols' => ['https'],
                'on_redirect' => function ($request, $response, UriInterface $uri) use (&$clientRedirects) {
                    $clientRedirects[] = (string) $uri;
                },
            ],
        ]));

        $cache->get(new GenericFile('https://files/image.jpg'), $this->noop);

        $options = $captured[0];
        $this->assertTrue($options['curl'][CURLOPT_SSL_VERIFYSTATUS]);
        $this->assertSame(1, $options['curl'][CURLOPT_LOW_SPEED_LIMIT]);
        $this->assertSame(15, $options['curl'][CURLOPT_LOW_SPEED_TIME]);
        $this->assertSame(3, $options['allow_redirects']['max']);
        $this->assertSame(['https'], $options['allow_redirects']['protocols']);
        $this->assertSame(['https://files/final.jpg'], $clientRedirects);
    }

    public function testInjectedClientOnRedirectRunsOnlyAfterHostValidation()
    {
        $clientRedirects = [];
        $mock = new MockHandler([
            new Response(301, ['Location' => 'https://evil.com/steal']),
        ]);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'allowed_hosts' => ['files'],
        ], new Client([
            'handler' => HandlerStack::create($mock),
            'allow_redirects' => [
                'on_redirect' => function ($request, $response, UriInterface $uri) use (&$clientRedirects) {
                    $clientRedirects[] = (string) $uri;
                },
            ],
        ]));

        try {
            $cache->get(new GenericFile('https://files/image.jpg'), $this->noop);
            $this->fail('Expected HostNotAllowedException to be thrown.');
        } catch (HostNotAllowedException) {
        }

        $this->assertSame([], $clientRedirects);
    }

    public function testRedirectToDisallowedHostIsBlocked()
    {
        $mock = new MockHandler([
            new Response(301, ['Location' => 'https://evil.com/steal'], ''),
            new Response(200, [], 'must never be fetched'),
        ]);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'allowed_hosts' => ['files'],
        ], new Client(['handler' => HandlerStack::create($mock)]));

        $this->expectException(HostNotAllowedException::class);
        $cache->get(new GenericFile('https://files/image.jpg'), $this->noop);
    }

    // =========================================================================
    // URL Sanitization Tests
    // =========================================================================

    // =========================================================================
    // Config Validation Tests
    // =========================================================================

    public function testConfigValidationThrowsOnZeroMaxFileSize()
    {
        $this->expectException(InvalidConfigurationException::class);
        new FileStash(['path' => $this->cachePath, 'max_file_size' => 0]);
    }

    // =========================================================================
    // v5 write protocol (claim + temp + atomic rename) and lifecycle locking
    // =========================================================================

    public function testNestedGetOnceInsideBatchDoesNotDeadlock()
    {
        $cache = $this->createCache();
        $outer = new GenericFile('fixtures://test-image.jpg');
        $inner = new GenericFile('fixtures://test-file.txt');

        $start = microtime(true);
        $innerPath = null;
        $innerExistedInsideCallback = null;

        $cache->batch([$outer], function ($files, $paths) use ($cache, $inner, &$innerPath, &$innerExistedInsideCallback) {
            $innerPath = $cache->getOnce($inner, fn ($f, $p) => $p);
            // The once-eviction is deferred until the outer batch releases
            // its shared lifecycle lock.
            $innerExistedInsideCallback = file_exists($innerPath);

            return $paths;
        });

        $this->assertNotNull($innerPath);
        $this->assertTrue($innerExistedInsideCallback);
        $this->assertFileDoesNotExist($innerPath); // evicted after the outer batch
        $this->assertLessThan(
            5.0,
            microtime(true) - $start,
            'Nested getOnce must not wait for the lifecycle lock'
        );
    }

    public function testNestedBatchInsideBatchCallback()
    {
        $cache = $this->createCache();
        $a = new GenericFile('fixtures://test-image.jpg');
        $b = new GenericFile('fixtures://test-file.txt');

        $result = $cache->batch([$a], function ($files, $paths) use ($cache, $b) {
            return $cache->batch([$b], fn ($f, $p) => $p[0]);
        });

        $this->assertFileExists($result);
    }

    public function testClearInsideBatchCallbackThrowsLogicException()
    {
        $cache = $this->createCache();
        $file = new GenericFile('fixtures://test-image.jpg');

        $this->expectException(\LogicException::class);

        $cache->batch([$file], function () use ($cache) {
            $cache->clear();
        });
    }

    public function testForgetInsideBatchCallbackIsDeferredUntilBatchEnds()
    {
        $cache = $this->createCache();
        $file = new GenericFile('fixtures://test-image.jpg');
        $other = new GenericFile('fixtures://test-file.txt');

        $otherPath = $cache->get($other, $this->noop);
        $this->assertFileExists($otherPath);

        $cache->batch([$file], function () use ($cache, $other, $otherPath) {
            // Nested under the shared lifecycle lock: forget schedules the
            // deletion (true) but the file survives the whole callback.
            $this->assertTrue($cache->forget($other));
            $this->assertFileExists($otherPath);

            return null;
        });

        $this->assertFileDoesNotExist($otherPath);
    }

    public function testForgetOutsideBatchDeletesImmediately()
    {
        $cache = $this->createCache();
        $file = new GenericFile('fixtures://test-file.txt');

        $path = $cache->get($file, $this->noop);
        $this->assertFileExists($path);

        $this->assertTrue($cache->forget($file));
        $this->assertFileDoesNotExist($path);
    }

    public function testDeferredForgetFlushesEvenWhenCallbackThrows()
    {
        $cache = $this->createCache();
        $file = new GenericFile('fixtures://test-image.jpg');
        $other = new GenericFile('fixtures://test-file.txt');

        $otherPath = $cache->get($other, $this->noop);

        try {
            $cache->batch([$file], function () use ($cache, $other) {
                $this->assertTrue($cache->forget($other));

                throw new \RuntimeException('callback failure');
            });
            $this->fail('Expected the callback exception to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('callback failure', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($otherPath);
    }

    public function testDeferredForgetFlushesOnlyAfterOutermostBatch()
    {
        $cache = $this->createCache();
        $outer = new GenericFile('fixtures://test-image.jpg');
        $inner = new GenericFile('fixtures://test-file.txt');
        $this->app['files']->put("{$this->diskPath}/other.txt", 'other');
        $other = new GenericFile('test://other.txt');

        $otherPath = $cache->get($other, $this->noop);
        $existedAfterInnerBatch = null;

        $cache->batch([$outer], function () use ($cache, $inner, $other, $otherPath, &$existedAfterInnerBatch) {
            $cache->batch([$inner], function () use ($cache, $other) {
                $this->assertTrue($cache->forget($other));
            });

            // The inner batch is a nested frame: releasing it must not flush.
            $existedAfterInnerBatch = file_exists($otherPath);
        });

        $this->assertTrue($existedAfterInnerBatch);
        $this->assertFileDoesNotExist($otherPath);
    }

    public function testDeferredForgetFromSecondInstanceFlushesAfterBatch()
    {
        $cacheA = $this->createCache();
        $cacheB = $this->createCache();
        $file = new GenericFile('fixtures://test-image.jpg');
        $other = new GenericFile('fixtures://test-file.txt');

        $otherPath = $cacheA->get($other, $this->noop);

        // The lifecycle registry is per-process: instance B sees A's shared
        // lock and defers; its queue flushes when A's batch releases.
        $cacheA->batch([$file], function () use ($cacheB, $other, $otherPath) {
            $this->assertTrue($cacheB->forget($other));
            $this->assertFileExists($otherPath);
        });

        $this->assertFileDoesNotExist($otherPath);
    }

    public function testDeferredEvictionEventFiresAfterBatch()
    {
        $dispatched = [];
        $dispatcher = $this->createStub(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $cache = new FileStash(
            ['path' => $this->cachePath, 'events_enabled' => true],
            null,
            null,
            null,
            null,
            $dispatcher
        );

        $file = new GenericFile('fixtures://test-image.jpg');
        $other = new GenericFile('fixtures://test-file.txt');
        $otherPath = $cache->get($other, $this->noop);

        $evictedInsideCallback = null;
        $cache->batch([$file], function () use ($cache, $other, &$evictedInsideCallback, &$dispatched) {
            $cache->forget($other);
            $evictedInsideCallback = array_filter($dispatched, fn ($e) => $e instanceof CacheFileEvicted);
        });

        $this->assertSame([], $evictedInsideCallback, 'Eviction events must fire at flush time, not inside the callback.');

        $evicted = array_values(array_filter($dispatched, fn ($e) => $e instanceof CacheFileEvicted));
        $this->assertCount(1, $evicted);
        $this->assertSame($otherPath, $evicted[0]->path);
    }

    public function testChunkedBatchKeepsNestedForgottenFileForWholeCallback()
    {
        $cache = $this->createCache(['batch_chunk_size' => 1]);
        $this->app['files']->put("{$this->diskPath}/third.txt", 'third');
        $files = [
            new GenericFile('fixtures://test-image.jpg'),
            new GenericFile('fixtures://test-file.txt'),
            new GenericFile('test://third.txt'),
        ];

        $paths = $cache->batch($files, function ($files, $paths) use ($cache) {
            // Chunked mode: per-file locks are already released, only the
            // lifecycle lock protects the files — the deferred forget must
            // keep the file alive for the whole callback anyway.
            $this->assertTrue($cache->forget($files[0]));

            foreach ($paths as $path) {
                $this->assertFileExists($path);
            }

            return $paths;
        });

        $this->assertFileDoesNotExist($paths[0]);
        $this->assertFileExists($paths[1]);
        $this->assertFileExists($paths[2]);
    }

    public function testForgetFromPruneListenerIsDeferredUntilPruneEnds()
    {
        $other = new GenericFile('fixtures://test-file.txt');
        $otherPath = $this->getCachedPath('fixtures://test-file.txt');

        $cacheRef = null;
        $existedDuringPrune = null;
        $dispatcher = $this->createStub(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$cacheRef, $other, $otherPath, &$existedDuringPrune) {
            if ($event instanceof CacheFileEvicted && $event->reason === 'pruned_age') {
                // Listener reacting to an eviction while prune holds the
                // shared lifecycle lock: the forget must be deferred.
                $cacheRef->forget($other);
                $existedDuringPrune = file_exists($otherPath);
            }
        });

        $cache = new FileStash(
            ['path' => $this->cachePath, 'events_enabled' => true, 'max_age' => 60],
            null,
            null,
            null,
            null,
            $dispatcher
        );
        $cacheRef = $cache;

        $cache->get($other, $this->noop);
        touch($otherPath); // keep fresh

        $stalePath = $this->getCachedPath('abc://stale-file');
        file_put_contents($stalePath, 'stale');
        touch($stalePath, time() - 7200);

        $stats = $cache->prune();

        $this->assertEquals(1, $stats['deleted']);
        $this->assertTrue($existedDuringPrune);
        $this->assertFileDoesNotExist($otherPath);
    }

    public function testExistsRemoteAllowsMimeTypeWithCharsetParameter()
    {
        $cache = $this->createCacheWithMockClient([
            new Response(200, ['Content-Type' => 'Image/JPEG; charset=binary', 'Content-Length' => '100']),
        ], ['mime_types' => ['image/jpeg']]);

        $this->assertTrue($cache->exists(new GenericFile('https://files/image.jpg')));
    }

    public function testMimeWhitelistIsCaseInsensitive()
    {
        $cache = $this->createCache(['mime_types' => ['IMAGE/JPEG']]);

        $path = $cache->get(new GenericFile('fixtures://test-image.jpg'), $this->noop);

        $this->assertFileExists($path);
    }

    public function testPruneInfrastructureCleanupEmitsNoEvictionEvents()
    {
        $dispatched = [];
        $dispatcher = $this->createStub(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $cache = new FileStash(
            ['path' => $this->cachePath, 'events_enabled' => true],
            null,
            null,
            null,
            null,
            $dispatcher
        );

        // Orphaned temp file (past the grace period) and an idle claim file.
        $tempPath = $this->cachePath.'/'.str_repeat('a', 64).'.123.'.str_repeat('b', 16).'.tmp';
        file_put_contents($tempPath, 'partial');
        touch($tempPath, time() - 120);
        $claimPath = $this->cachePath.'/.locks/'.str_repeat('c', 64).'.lock';
        $this->app['files']->makeDirectory(dirname($claimPath), 0755, true, true);
        touch($claimPath);

        $stats = $cache->prune();

        $this->assertFileDoesNotExist($tempPath);
        $this->assertFileDoesNotExist($claimPath);
        $this->assertEquals(0, $stats['deleted']);
        $this->assertSame(0, $cache->metrics()->evictions);
        $this->assertSame(
            [],
            array_filter($dispatched, fn ($e) => $e instanceof CacheFileEvicted),
            'Temp/claim garbage collection must not emit eviction events.'
        );
        $this->assertCount(1, array_filter($dispatched, fn ($e) => $e instanceof CachePruneCompleted));
    }

    /**
     * prune() only collects temp files of crashed writers: a young temp file
     * may belong to a writer between fopen() and flock() (60 s grace), and a
     * locked one to a live (slow) download, however old.
     */
    #[DataProvider('tempFileProvider')]
    public function testPruneCollectsOnlyOrphanedTempFiles(int $age, bool $locked, bool $collected)
    {
        $cache = $this->createCache();
        $tempPath = $this->cachePath.'/'.str_repeat('a', 64).'.123.'.str_repeat('b', 16).'.tmp';
        file_put_contents($tempPath, 'partial');
        touch($tempPath, time() - $age);
        $writer = fopen($tempPath, 'rb');
        if ($locked) {
            $this->assertTrue(flock($writer, LOCK_EX));
        }

        try {
            $cache->prune();
        } finally {
            fclose($writer);
        }

        $this->assertSame(! $collected, file_exists($tempPath));
    }

    public static function tempFileProvider(): array
    {
        return [
            'within the grace period' => [55, false, false],
            'past the grace period' => [65, false, true],
            'locked by a live download' => [3600, true, false],
        ];
    }

    public function testPruneOnMissingDirectoryDispatchesCompletionEvent()
    {
        $dispatched = [];
        $dispatcher = $this->createStub(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $nonExistentPath = sys_get_temp_dir().'/non_existent_path_'.bin2hex(random_bytes(8));
        $cache = new FileStash(
            ['path' => $nonExistentPath, 'events_enabled' => true],
            null,
            null,
            null,
            null,
            $dispatcher
        );

        $cache->prune();

        $completed = array_values(array_filter($dispatched, fn ($e) => $e instanceof CachePruneCompleted));
        $this->assertCount(1, $completed);
        $this->assertTrue($completed[0]->completed);
        $this->assertSame(0, $completed[0]->deleted);
        $this->assertDirectoryDoesNotExist($nonExistentPath);
    }

    public function testForgetReturnsFalseWhileAChunkedBatchHoldsThePinLock()
    {
        $cache = $this->createCache([
            'lifecycle_lock_timeout' => 0.05,
        ]);
        $file = new GenericFile('fixtures://test-file.txt');
        $path = $cache->get($file, $this->noop);

        // A chunked batch of another process: its entries are not locked
        // individually during the callback, only the pin lock protects them.
        $lock = fopen("{$this->cachePath}/.pin.lock", 'c+');
        $this->assertTrue(flock($lock, LOCK_SH));

        try {
            $this->assertFalse($cache->forget($file));
            $this->assertFileExists($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Another worker inside get()/batch() holds the lifecycle lock shared for
     * its whole callback. Deleting an entry it does not use must not wait
     * for it — with lifecycle_lock_timeout = 0.05 the old exclusive
     * lifecycle lock gave up and left the entry behind.
     */
    #[DataProvider('unrelatedWorkDeletionProvider')]
    public function testDeletionsDoNotWaitForBatchesOfOtherWorkers(string $operation)
    {
        $cache = $this->createCache(['lifecycle_lock_timeout' => 0.05]);
        $file = new GenericFile('fixtures://test-file.txt');
        $path = $cache->get($file, $this->noop);

        $lock = fopen("{$this->cachePath}/.lifecycle.lock", 'c+');
        $this->assertTrue(flock($lock, LOCK_SH));

        try {
            match ($operation) {
                'forget' => $this->assertTrue($cache->forget($file)),
                'getOnce' => $cache->getOnce($file, $this->noop),
                'deferred forget' => $cache->batch([$file], fn () => $this->assertTrue($cache->forget($file))),
            };
            $this->assertFileDoesNotExist($path);
        } finally {
            fclose($lock);
        }
    }

    public static function unrelatedWorkDeletionProvider(): array
    {
        return [
            'forget' => ['forget'],
            'getOnce cleanup' => ['getOnce'],
            'deferred forget' => ['deferred forget'],
        ];
    }

    public function testForgetSkipsAnEntryAnotherWorkerIsReading()
    {
        $cache = $this->createCache(['lifecycle_lock_timeout' => 5]);
        $file = new GenericFile('fixtures://test-file.txt');
        $path = $cache->get($file, $this->noop);

        // Another worker inside get() holds the entry shared.
        $reader = fopen($path, 'rb');
        $this->assertTrue(flock($reader, LOCK_SH));

        try {
            $start = microtime(true);
            $this->assertFalse($cache->forget($file));
            $this->assertLessThan(1.0, microtime(true) - $start, 'forget() must skip, not wait');
            $this->assertFileExists($path);
        } finally {
            fclose($reader);
        }
    }

    public function testDeferredFlushLogsFailuresInsteadOfSwallowingThem()
    {
        $logger = new RecordingLogger;
        $events = new \Illuminate\Events\Dispatcher;
        $events->listen(CacheFileEvicted::class, function () {
            throw new \LogicException('listener failed');
        });
        $cache = new FileStash(
            ['path' => $this->cachePath, 'events_enabled' => true],
            null,
            null,
            null,
            $logger,
            $events
        );
        $file = new GenericFile('fixtures://test-file.txt');

        $cache->batch([$file], fn () => $cache->forget($file));

        $warnings = $logger->messages('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('deferred', $warnings[0]);
    }

    public function testBatchThrowsLifecycleLockTimeoutException()
    {
        $cache = $this->createCache([
            'lifecycle_lock_timeout' => 0.05,
        ]);
        $file = new GenericFile('fixtures://test-file.txt');
        $cache->get($file, $this->noop);

        $lock = fopen("{$this->cachePath}/.lifecycle.lock", 'c+');
        $this->assertTrue(flock($lock, LOCK_EX));

        try {
            $this->expectException(LifecycleLockTimeoutException::class);
            $cache->batch([$file]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testLifecycleLockLivesInsideCacheDirectory()
    {
        $cache = $this->createCache();
        $cache->batch([]);

        $this->assertFileExists("{$this->cachePath}/.lifecycle.lock");
    }

    public function testNoTempFilesRemainAfterGet()
    {
        $cache = $this->createCache();
        $cache->get(new GenericFile('fixtures://test-image.jpg'), $this->noop);

        $this->assertSame([], glob("{$this->cachePath}/*.tmp"));
    }

    public function testMimeRejectedDownloadLeavesNoArtifacts()
    {
        $cache = $this->createCache(['mime_types' => ['image/jpeg']]);
        $file = new GenericFile('fixtures://test-file.txt');

        try {
            $cache->get($file, $this->noop);
            $this->fail('Expected MimeTypeIsNotAllowedException was not thrown');
        } catch (MimeTypeIsNotAllowedException $e) {
            // expected
        }

        $this->assertFileDoesNotExist($this->getCachedPath('fixtures://test-file.txt'));
        $this->assertSame([], glob("{$this->cachePath}/*.tmp"));
    }

    public function testPruneStatsIgnoreInfrastructureFiles()
    {
        $cache = $this->createCache();
        $file = new GenericFile('fixtures://test-image.jpg');
        $path = $cache->get($file, $this->noop);

        // Entry + .lifecycle.lock + .locks/{hash}.lock exist; only the entry counts.
        $stats = $cache->prune();

        $this->assertEquals(0, $stats['deleted']);
        $this->assertEquals(1, $stats['remaining']);
        $this->assertEquals(filesize($path), $stats['total_size']);
    }

    public function testPruneRemovesOrphanedTempAfterGrace()
    {
        $cache = $this->createCache();
        $hash = hash('sha256', 'https://example.com/orphan');
        $orphan = "{$this->cachePath}/{$hash}.12345.aabbccddeeff0011.tmp";
        $fresh = "{$this->cachePath}/{$hash}.12345.aabbccddeeff0022.tmp";

        $this->app['files']->put($orphan, 'partial download');
        touch($orphan, time() - 120); // older than the grace period
        $this->app['files']->put($fresh, 'partial download');

        $stats = $cache->prune();

        $this->assertFileDoesNotExist($orphan);
        $this->assertFileExists($fresh, 'A temp within the grace period must survive prune');

        // Temp files are not cache entries and never appear in the stats.
        $this->assertEquals(0, $stats['deleted']);
        $this->assertEquals(0, $stats['remaining']);
        $this->assertEquals(0, $stats['total_size']);
    }

    public function testPruneKeepsLockedTemp()
    {
        $cache = $this->createCache();
        $hash = hash('sha256', 'https://example.com/downloading');
        $locked = "{$this->cachePath}/{$hash}.99999.aabbccddeeff0033.tmp";

        $this->app['files']->put($locked, 'active download');
        touch($locked, time() - 120);

        $handle = fopen($locked, 'rb');
        $this->assertTrue(flock($handle, LOCK_EX));

        try {
            $cache->prune();
            $this->assertFileExists($locked, 'An exclusively locked temp belongs to a live download');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testPruneRemovesIdleClaimFiles()
    {
        $cache = $this->createCache();
        $file = new GenericFile('fixtures://test-image.jpg');
        $cache->get($file, $this->noop);

        $claim = "{$this->cachePath}/.locks/".hash('sha256', 'fixtures://test-image.jpg').'.lock';
        $this->assertFileExists($claim);

        $cache->prune();

        $this->assertFileDoesNotExist($claim);
    }

    public function testPruneKeepsHeldClaimFiles()
    {
        $cache = $this->createCache();
        $claimDir = "{$this->cachePath}/.locks";
        $this->app['files']->makeDirectory($claimDir, 0755, true);
        $claim = "{$claimDir}/".str_repeat('a', 64).'.lock';
        touch($claim);

        $handle = fopen($claim, 'rb');
        $this->assertTrue(flock($handle, LOCK_EX));

        try {
            $cache->prune();
            $this->assertFileExists($claim, 'A held claim belongs to an active download');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testClearRemovesEntriesTempsAndClaims()
    {
        $cache = $this->createCache();
        $file = new GenericFile('fixtures://test-image.jpg');
        $path = $cache->get($file, $this->noop);

        $orphan = "{$this->cachePath}/".str_repeat('b', 64).'.123.aabbccddeeff0044.tmp';
        $this->app['files']->put($orphan, 'partial download');

        $cache->clear();

        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($orphan);
        $this->assertSame([], glob("{$this->cachePath}/.locks/*.lock"));
    }

    public function testTwoInstancesNestedLifecycleCallsDoNotDeadlock()
    {
        // Two manually constructed instances share the same cache path; the
        // lifecycle-lock registry is per process and keyed by lock path, so
        // nested calls across instances nest instead of self-deadlocking.
        $outer = $this->createCache(['lifecycle_lock_timeout' => 5]);
        $inner = $this->createCache(['lifecycle_lock_timeout' => 5]);

        $outerFile = new GenericFile('fixtures://test-image.jpg');
        $innerFile = new GenericFile('fixtures://test-file.txt');

        $result = $outer->batch([$outerFile], function () use ($inner, $innerFile) {
            return $inner->get($innerFile, fn ($f, $path) => file_exists($path));
        });

        $this->assertTrue($result);
    }

    public function testClearOnSecondInstanceInsideBatchCallbackThrowsLogicException()
    {
        $outer = $this->createCache(['lifecycle_lock_timeout' => 5]);
        $inner = $this->createCache(['lifecycle_lock_timeout' => 5]);

        $file = new GenericFile('fixtures://test-image.jpg');

        $this->expectException(\LogicException::class);

        $outer->batch([$file], function () use ($inner) {
            $inner->clear();
        });
    }
}
