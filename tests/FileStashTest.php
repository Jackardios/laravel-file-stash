<?php

namespace Jackardios\FileStash\Tests;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Jackardios\FileStash\Contracts\File;
use Jackardios\FileStash\Events\CacheFileEvicted;
use Jackardios\FileStash\Events\CacheFileRetrieved;
use Jackardios\FileStash\Events\CacheHit;
use Jackardios\FileStash\Events\CacheMiss;
use Jackardios\FileStash\Events\CachePruneCompleted;
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
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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
        $suffix = uniqid('', true);
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
     * Get the Guzzle client config from a FileStash instance (via its RemoteFetcher).
     */
    protected function getClientConfig(FileStash $cache): array
    {
        $fetcherProperty = new \ReflectionProperty($cache, 'remoteFetcher');
        $fetcher = $fetcherProperty->getValue($cache);

        $clientProperty = new \ReflectionProperty($fetcher, 'client');

        return $clientProperty->getValue($fetcher)->getConfig();
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
            new Response(200, ['Content-Length' => 100], $this->getTestImageContent()),
        ], ['max_file_size' => 1]);

        try {
            $cache->get($file, $this->noop);
            $this->fail('Expected FileIsTooLargeException to be thrown.');
        } catch (FileIsTooLargeException $exception) {
            $this->assertFileDoesNotExist($cachedPath);
        }
    }

    public function testGetDiskDoesNotExist()
    {
        $file = new GenericFile('abc://files/image.jpg');
        $cache = $this->createCache();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Disk [abc] does not have a configured driver');
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

    public function testGetIgnoreZeroSize()
    {
        // Zero-length entries are treated as v4 artifacts only while the
        // legacy lifecycle lock (v4/v5 coexistence) is enabled.
        $cache = $this->createCache(['legacy_lifecycle_lock' => true]);
        $url = 'fixtures://test-file.txt';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        touch($cachedPath);
        $this->assertEquals(0, filesize($cachedPath));

        $cache->get($file, fn ($file, $path) => $file);

        $this->assertNotEquals(0, filesize($cachedPath));
    }

    public function testZeroByteEntryIsServedWhenLegacyLockDisabled()
    {
        // The empty mock queue proves no refetch happens: any request would
        // throw on queue exhaustion.
        $cache = $this->createCacheWithMockClient([], ['legacy_lifecycle_lock' => false]);
        $url = 'https://files/empty.bin';
        $cachedPath = $this->getCachedPath($url);

        touch($cachedPath);

        $path = $cache->get(new GenericFile($url), $this->noop);

        $this->assertSame($cachedPath, $path);
        $this->assertSame(0, filesize($cachedPath));
    }

    public function testEmptyRemoteBodyIsCachedWhenLegacyLockDisabled()
    {
        $cache = $this->createCacheWithMockClient([
            new Response(200, [], ''),
        ], ['legacy_lifecycle_lock' => false]);
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
        ], ['legacy_lifecycle_lock' => false, 'mime_types' => ['image/jpeg']]);

        $this->expectException(MimeTypeIsNotAllowedException::class);
        $cache->get(new GenericFile('https://files/empty.bin'), $this->noop);
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
        $this->app['files']->put("{$this->cachePath}/abc", 'abc');
        touch("{$this->cachePath}/abc", time() - 1);
        $this->app['files']->put("{$this->cachePath}/def", 'def');

        $cache = $this->createCache(['max_size' => 3]);
        $cache->prune();

        $this->assertFileDoesNotExist("{$this->cachePath}/abc");
        $this->assertFileExists("{$this->cachePath}/def");

        $cache = $this->createCache(['max_size' => 0]);
        $cache->prune();

        $this->assertFileDoesNotExist("{$this->cachePath}/def");
    }

    public function testPruneAge()
    {
        $this->app['files']->put("{$this->cachePath}/abc", 'abc');
        touch("{$this->cachePath}/abc", time() - 61);
        $this->app['files']->put("{$this->cachePath}/def", 'def');

        $cache = $this->createCache(['max_age' => 1]);
        $cache->prune();

        $this->assertFileDoesNotExist("{$this->cachePath}/abc");
        $this->assertFileExists("{$this->cachePath}/def");
    }

    public function testClear()
    {
        $this->app['files']->put("{$this->cachePath}/abc", 'abc');
        $this->app['files']->put("{$this->cachePath}/def", 'abc');

        $handle = fopen("{$this->cachePath}/def", 'rb');
        flock($handle, LOCK_SH);

        try {
            $this->createCache()->clear();

            $this->assertFileExists("{$this->cachePath}/def");
            $this->assertFileDoesNotExist("{$this->cachePath}/abc");
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

    public function testExistsRemote500()
    {
        $file = new GenericFile('https://example.com/file');
        $cache = $this->createCacheWithMockClient([new Response(500)]);

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
            [new Response(200, ['content-length' => 100])],
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
        $unlockedFile = "{$this->cachePath}/unlocked";
        $lockedFile = "{$this->cachePath}/locked";

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

    public function testBatchChunking()
    {
        // Create 5 different files on disk
        for ($i = 0; $i < 5; $i++) {
            $this->app['files']->put("{$this->diskPath}/chunk-file-{$i}.txt", "content-{$i}");
        }

        $files = [];
        for ($i = 0; $i < 5; $i++) {
            $files[] = new GenericFile("test://chunk-file-{$i}.txt");
        }

        $cache = new FileStash([
            'path' => $this->cachePath,
            'batch_chunk_size' => 2, // Process in chunks of 2
        ]);

        $callbackCalled = false;
        $paths = $cache->batch($files, function ($receivedFiles, $receivedPaths) use (&$callbackCalled) {
            $callbackCalled = true;
            $this->assertCount(5, $receivedFiles);
            $this->assertCount(5, $receivedPaths);
            // All paths should be unique (different files → different cache paths)
            $this->assertCount(5, array_unique($receivedPaths));

            return $receivedPaths;
        });

        $this->assertTrue($callbackCalled);
        $this->assertCount(5, $paths);
        $this->assertCount(5, array_unique($paths));
    }

    public function testBatchChunkingDisabled()
    {
        $this->app['files']->put("{$this->diskPath}/test-image.jpg", 'abc');

        $files = [];
        for ($i = 0; $i < 5; $i++) {
            $files[] = new GenericFile('test://test-image.jpg');
        }

        // batch_chunk_size = -1 disables chunking
        $cache = new FileStash([
            'path' => $this->cachePath,
            'batch_chunk_size' => -1,
        ]);

        $paths = $cache->batch($files, function ($files, $paths) {
            return $paths;
        });

        $this->assertCount(5, $paths);
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

    public function testLifecycleLockPathUsesNormalizedCachePath()
    {
        $cacheWithPlainPath = new FileStash(['path' => $this->cachePath]);
        $cacheWithTrailingSlash = new FileStash(['path' => $this->cachePath.'/']);

        $method = new ReflectionMethod(FileStash::class, 'getLifecycleLockPath');
        $method->setAccessible(true);

        $this->assertSame(
            $method->invoke($cacheWithPlainPath),
            $method->invoke($cacheWithTrailingSlash)
        );
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
            protected function withLifecycleExclusiveLock(callable $callback)
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
        $nonExistentPath = sys_get_temp_dir().'/non_existent_path_'.uniqid();
        $cache = new FileStash(['path' => $nonExistentPath]);

        $this->assertSame(['deleted' => 0, 'remaining' => 0, 'total_size' => 0, 'completed' => true], $cache->prune());
    }

    public function testClearOnNonExistentPath()
    {
        $nonExistentPath = sys_get_temp_dir().'/non_existent_path_'.uniqid();
        $cache = new FileStash(['path' => $nonExistentPath]);

        $this->assertDirectoryDoesNotExist($nonExistentPath);
        $cache->clear();
        $this->assertDirectoryDoesNotExist($nonExistentPath);
    }

    public function testRetrieveCreatesPathIfNotExists()
    {
        $newPath = sys_get_temp_dir().'/new_cache_path_'.uniqid();
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
            new Response(200, ['content-length' => 1000000]), // 1MB
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
        $coldPath = sys_get_temp_dir().'/file_stash_cold_'.uniqid();
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
        $this->app['files']->put("{$this->cachePath}/old", str_repeat('a', 100));
        touch("{$this->cachePath}/old", time() - 10, time() - 10);

        $this->app['files']->put("{$this->cachePath}/new", str_repeat('b', 100));
        // new file has current atime

        clearstatcache();

        $cache = new FileStash([
            'path' => $this->cachePath,
            'max_size' => 100, // Only allow 100 bytes
            'max_age' => 60, // Don't prune by age
        ]);

        $cache->prune();

        // Old file should be deleted, new file should remain
        $this->assertFileDoesNotExist("{$this->cachePath}/old");
        $this->assertFileExists("{$this->cachePath}/new");
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
            $this->app['files']->put("{$this->cachePath}/file{$i}", str_repeat('x', 100));
            touch("{$this->cachePath}/file{$i}", time() - 7200); // 2 hours old
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

    public function testGuzzleClientHasMaxRedirects()
    {
        $cache = $this->createCache(['max_redirects' => 3]);
        $config = $this->getClientConfig($cache);

        $this->assertArrayHasKey('allow_redirects', $config);
        $this->assertEquals(3, $config['allow_redirects']['max']);
        $this->assertArrayHasKey('on_redirect', $config['allow_redirects']);
        $this->assertIsCallable($config['allow_redirects']['on_redirect']);
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
        $this->assertNotEmpty($path);
    }

    public function testHttpRetryUsesExponentialBackoff()
    {
        // Verify retry works with multiple failures followed by success
        $cache = $this->createCacheWithMockClient([
            new Response(503),
            new Response(503),
            new Response(200, [], $this->getTestImageContent()),
        ], [
            'http_retries' => 2,
            'http_retry_delay' => 1, // 1ms base delay to keep test fast
        ]);

        $file = new GenericFile('https://example.com/image.jpg');
        $path = $cache->get($file, $this->noop);
        $this->assertNotEmpty($path);
    }

    public function testCopyStreamWithSizeLimitHandlesPhpIntMax()
    {
        // With max_file_size set to PHP_INT_MAX, should not overflow
        $cache = $this->createCacheWithMockClient([
            new Response(200, [], $this->getTestImageContent()),
        ], ['max_file_size' => PHP_INT_MAX]);

        $file = new GenericFile('https://example.com/image.jpg');
        $path = $cache->get($file, $this->noop);
        $this->assertFileExists($path);
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
        $this->app['files']->put("{$this->cachePath}/testfile", 'content');
        $cache->clear();

        $evictionEvents = array_filter($dispatched, fn ($e) => $e instanceof CacheFileEvicted);
        $this->assertGreaterThanOrEqual(1, count($evictionEvents));
        $evictionEvent = array_values($evictionEvents)[0];
        $this->assertEquals('cleared', $evictionEvent->reason);
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

    public function testGetCachedPathReturnsConsistentResults()
    {
        $cache = $this->createCache();
        $file = new GenericFile('https://example.com/image.jpg');

        $method = new ReflectionMethod($cache, 'getCachedPath');
        $method->setAccessible(true);

        $path1 = $method->invoke($cache, $file);
        $path2 = $method->invoke($cache, $file);
        $this->assertEquals($path1, $path2);
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
        $this->app['files']->put("{$this->cachePath}/expired", 'content');
        touch("{$this->cachePath}/expired", time() - 7200);

        $cache = new FileStash(
            ['path' => $this->cachePath, 'max_age' => 1, 'events_enabled' => true],
            null,
            null,
            null,
            null,
            $dispatcher
        );

        $cache->prune();

        $evictions = array_filter($dispatched, fn ($e) => $e instanceof CacheFileEvicted);
        $this->assertGreaterThanOrEqual(1, count($evictions));
        $eviction = array_values($evictions)[0];
        $this->assertEquals('pruned_age', $eviction->reason);
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

    public function testGuzzleClientHasTimeoutSettings()
    {
        $cache = $this->createCache([
            'timeout' => 30,
            'connect_timeout' => 10,
            'read_timeout' => 15,
        ]);
        $config = $this->getClientConfig($cache);

        $this->assertEquals(30, $config['timeout']);
        $this->assertEquals(10, $config['connect_timeout']);
        $this->assertFalse($config['http_errors']);

        // read_timeout maps to curl's low-speed abort (stall timeout)
        $this->assertArrayHasKey('curl', $config);
        $this->assertSame(1, $config['curl'][CURLOPT_LOW_SPEED_LIMIT]);
        $this->assertSame(15, $config['curl'][CURLOPT_LOW_SPEED_TIME]);
    }

    public function testGuzzleClientRoundsUpFractionalReadTimeoutForCurl()
    {
        $cache = $this->createCache(['read_timeout' => 0.5]);
        $config = $this->getClientConfig($cache);

        // Sub-second stall timeouts are rounded up to curl's 1-second minimum
        $this->assertSame(1, $config['curl'][CURLOPT_LOW_SPEED_TIME]);
    }

    public function testGuzzleClientClampsNegativeTimeoutsToZero()
    {
        $cache = $this->createCache([
            'timeout' => -1,
            'connect_timeout' => -1,
            'read_timeout' => -1,
        ]);
        $config = $this->getClientConfig($cache);

        $this->assertEquals(0, $config['timeout']);
        $this->assertEquals(0, $config['connect_timeout']);

        // read_timeout=-1 disables the curl low-speed abort entirely
        $this->assertArrayNotHasKey('curl', $config);
    }

    // =========================================================================
    // SSRF Redirect Protection Tests
    // =========================================================================

    public function testOnRedirectBlocksDisallowedHost()
    {
        $cache = $this->createCache(['allowed_hosts' => ['example.com']]);
        $config = $this->getClientConfig($cache);
        $onRedirect = $config['allow_redirects']['on_redirect'];

        $request = $this->createStub(RequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $uri = $this->createStub(UriInterface::class);
        $uri->method('__toString')->willReturn('https://evil.com/malicious');

        $this->expectException(HostNotAllowedException::class);
        $onRedirect($request, $response, $uri);
    }

    public function testOnRedirectAllowsAllowedHost()
    {
        $cache = $this->createCache(['allowed_hosts' => ['example.com']]);
        $config = $this->getClientConfig($cache);
        $onRedirect = $config['allow_redirects']['on_redirect'];

        $request = $this->createStub(RequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $uri = $this->createStub(UriInterface::class);
        $uri->method('__toString')->willReturn('https://example.com/redirect-target');

        // Should not throw
        $onRedirect($request, $response, $uri);
        $this->assertTrue(true);
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
        $finalBody = str_repeat('F', 100);
        $cache = $this->createCacheWithMockClient([
            new Response(301, ['Location' => 'https://files/final.jpg'], str_repeat('D', 300)),
            new Response(200, [], $finalBody),
        ], ['max_file_size' => 150]);

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

    public function testInjectedClientRedirectToDisallowedHostIsBlocked()
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
            new Response(200, ['Content-Type' => 'Image/JPEG; charset=binary', 'Content-Length' => 100]),
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

    public function testPruneOnMissingDirectoryDispatchesCompletionEvent()
    {
        $dispatched = [];
        $dispatcher = $this->createStub(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $nonExistentPath = sys_get_temp_dir().'/non_existent_path_'.uniqid();
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

    public function testZeroSizePurgeSkippedUnderForeignSharedLockRedownloadsSafely()
    {
        $cache = $this->createCache(['legacy_lifecycle_lock' => true]);
        $url = 'fixtures://test-file.txt';
        $cachedPath = $this->getCachedPath($url);

        touch($cachedPath);
        $reader = fopen($cachedPath, 'rb');
        $this->assertTrue(flock($reader, LOCK_SH));

        try {
            $path = $cache->get(new GenericFile($url), $this->noop);

            // The zero-byte purge was skipped (the reader holds a shared
            // lock); fresh content was atomically renamed over the path
            // instead, leaving the reader's inode untouched.
            $this->assertSame($cachedPath, $path);
            $this->assertSame(0, fstat($reader)['size']);
            $this->assertGreaterThan(0, filesize($cachedPath));
        } finally {
            flock($reader, LOCK_UN);
            fclose($reader);
        }
    }

    public function testDeleteEntryWithStreamSkipsWhenPathWasRepublished()
    {
        $cache = $this->createCache();
        $path = "{$this->cachePath}/entry";
        touch($path);

        // A reader opened the zero-byte inode before it was replaced.
        $stream = fopen($path, 'rb');
        $this->assertTrue(flock($stream, LOCK_SH));

        // Concurrent republish: a fresh file replaces the path (new inode).
        file_put_contents("{$path}.new", 'fresh content');
        rename("{$path}.new", $path);

        // The in-place upgrade must not delete the republished file: the
        // locked inode no longer matches the path (and fails the verify).
        $method = new ReflectionMethod($cache, 'deleteEntry');
        $result = $method->invoke(
            $cache,
            $path,
            'zero_size',
            $stream,
            static fn (array $s): bool => $s['size'] === 0 && ($s['nlink'] ?? 0) > 0
        );

        $this->assertSame(DeleteResult::Skipped, $result);
        $this->assertSame('fresh content', file_get_contents($path));
    }

    public function testForgetReturnsFalseOnLifecycleLockTimeout()
    {
        $cache = $this->createCache([
            'lifecycle_lock_timeout' => 0.05,
            'legacy_lifecycle_lock' => false,
        ]);
        $file = new GenericFile('fixtures://test-file.txt');
        $path = $cache->get($file, $this->noop);

        // Simulate another process holding the lifecycle lock exclusively.
        $lock = fopen("{$this->cachePath}/.lifecycle.lock", 'c+');
        $this->assertTrue(flock($lock, LOCK_EX));

        try {
            $this->assertFalse($cache->forget($file));
            $this->assertFileExists($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testBatchThrowsLifecycleLockTimeoutException()
    {
        $cache = $this->createCache([
            'lifecycle_lock_timeout' => 0.05,
            'legacy_lifecycle_lock' => false,
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
