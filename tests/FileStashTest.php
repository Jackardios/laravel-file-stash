<?php

namespace Jackardios\FileStash\Tests;

use Jackardios\FileStash\Contracts\File;
use Jackardios\FileStash\Exceptions\FileIsTooLargeException;
use Jackardios\FileStash\Exceptions\FileLockedException;
use Jackardios\FileStash\Exceptions\InvalidConfigurationException;
use Jackardios\FileStash\Exceptions\MimeTypeIsNotAllowedException;
use Jackardios\FileStash\FileStash;
use Jackardios\FileStash\GenericFile;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Jackardios\FileStash\Exceptions\FailedToRetrieveFileException;
use Jackardios\FileStash\Exceptions\HostNotAllowedException;
use Jackardios\FileStash\Events\CacheHit;
use Jackardios\FileStash\Events\CacheMiss;
use Jackardios\FileStash\Events\CacheFileRetrieved;
use Jackardios\FileStash\Events\CacheFileEvicted;
use Jackardios\FileStash\Events\CachePruneCompleted;
use Jackardios\FileStash\Support\CacheMetrics;
use Jackardios\FileStash\Testing\FileStashFake;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

class FileStashTest extends TestCase
{
    protected string $cachePath;
    protected string $diskPath;
    protected \Closure $noop;

    public function setUp(): void
    {
        parent::setUp();
        $suffix = uniqid('', true);
        $this->cachePath = sys_get_temp_dir().'/biigle_file_cache_test_'.$suffix;
        $this->diskPath = sys_get_temp_dir().'/biigle_file_cache_disk_'.$suffix;
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

    public function tearDown(): void
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
        return "{$this->cachePath}/" . hash('sha256', $url);
    }

    /**
     * Create a mock S3 filesystem.
     */
    protected function mockS3Filesystem($stream): void
    {
        config(['filesystems.disks.s3' => ['driver' => 's3']]);

        $filesystemManagerMock = $this->createMock(FilesystemManager::class);
        $filesystemMock = $this->createMock(FilesystemAdapter::class);
        $filesystemMock->method('readStream')->willReturn($stream);
        $filesystemMock->method('getDriver')->willReturn($filesystemMock);
        $filesystemMock->method('get')->willReturn($filesystemMock);
        $filesystemManagerMock->method('disk')->with('s3')->willReturn($filesystemMock);
        $this->app['filesystem'] = $filesystemManagerMock;
    }

    /**
     * Get test image content.
     */
    protected function getTestImageContent(): string
    {
        return file_get_contents(__DIR__.'/files/test-image.jpg');
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
        $this->expectExceptionMessage("Disk [abc] does not have a configured driver");
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
        $cache = $this->createCache();
        $url = 'fixtures://test-file.txt';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        touch($cachedPath);
        $this->assertEquals(0, filesize($cachedPath));

        $cache->get($file, fn ($file, $path) => $file);

        $this->assertNotEquals(0, filesize($cachedPath));
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

        $this->assertFileDoesNotExist($unlockedFile, "Unlocked file should be pruned.");
        $this->assertFileExists($lockedFile, "Locked file should NOT be pruned.");

        flock($handle, LOCK_UN);
        fclose($handle);

        $cache->prune();
        $this->assertFileDoesNotExist($lockedFile, "Unlocked file should be pruned now.");
    }

    #[DataProvider('provideUrlsForEncoding')]
    public function testEncodeUrl(string $inputUrl, string $expectedUrl)
    {
        $cache = $this->createCache();
        $method = new ReflectionMethod(FileStash::class, 'encodeUrl');
        $method->setAccessible(true);

        $this->assertEquals($expectedUrl, $method->invoke($cache, $inputUrl));
    }

    public static function provideUrlsForEncoding(): array
    {
        return [
            'no encoding needed' => ['http://example.com/path/file.jpg', 'http://example.com/path/file.jpg'],
            'space encoding' => ['http://example.com/path with space/file name.jpg', 'http://example.com/path%20with%20space/file%20name.jpg'],
            'plus sign not encoded' => ['http://example.com/path+plus/file+name.jpg', 'http://example.com/path+plus/file+name.jpg'],
            'mixed chars' => ['http://example.com/path with space/and+plus.jpg', 'http://example.com/path%20with%20space/and+plus.jpg'],
            'query string spaces encoded' => ['http://example.com/pa th?q=a+b c', 'http://example.com/pa%20th?q=a+b%20c'],
        ];
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
        $this->assertTrue(flock($reader1Handle, LOCK_SH), "Reader 1 failed to acquire LOCK_SH.");

        // The second reader tries to get the file (must successfully get LOCK_SH)
        $reader2Handle = null;
        $reader2Path = $cache->get($file, function ($file, $path) use (&$reader2Handle) {
            $reader2Handle = fopen($path, 'rb');
            $this->assertTrue(flock($reader2Handle, LOCK_SH), "Reader 2 failed to acquire LOCK_SH.");
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

    public function testAllowedHostsValidation()
    {
        $cache = new FileStash([
            'path' => $this->cachePath,
            'allowed_hosts' => ['example.com', 'cdn.example.com'],
        ]);

        $method = new ReflectionMethod(FileStash::class, 'validateHost');
        $method->setAccessible(true);

        // Should not throw for allowed host
        $method->invoke($cache, 'https://example.com/image.jpg');
        $method->invoke($cache, 'https://cdn.example.com/image.jpg');

        // Should throw for disallowed host
        $this->expectException(\Jackardios\FileStash\Exceptions\HostNotAllowedException::class);
        $method->invoke($cache, 'https://evil.com/image.jpg');
    }

    public function testAllowedHostsWildcard()
    {
        $cache = new FileStash([
            'path' => $this->cachePath,
            'allowed_hosts' => ['*.example.com'],
        ]);

        $method = new ReflectionMethod(FileStash::class, 'validateHost');
        $method->setAccessible(true);

        // Should allow subdomains
        $method->invoke($cache, 'https://cdn.example.com/image.jpg');
        $method->invoke($cache, 'https://images.cdn.example.com/image.jpg');

        // Should also allow the root domain
        $method->invoke($cache, 'https://example.com/image.jpg');

        // Should throw for different domain
        $this->expectException(\Jackardios\FileStash\Exceptions\HostNotAllowedException::class);
        $method->invoke($cache, 'https://notexample.com/image.jpg');
    }

    public function testAllowedHostsNullAllowsAll()
    {
        $cache = new FileStash([
            'path' => $this->cachePath,
            'allowed_hosts' => null,
        ]);

        $method = new ReflectionMethod(FileStash::class, 'validateHost');
        $method->setAccessible(true);

        // Should allow any host when allowed_hosts is null
        $this->assertNull($method->invoke($cache, 'https://any-domain.com/image.jpg'));
        $this->assertNull($method->invoke($cache, 'https://another-domain.org/file.png'));
    }

    public function testGenericFileThrowsOnEmptyUrl()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');

        new GenericFile('');
    }

    public function testEncodeUrlBrackets()
    {
        $cache = new FileStash(['path' => $this->cachePath]);
        $method = new ReflectionMethod(FileStash::class, 'encodeUrl');
        $method->setAccessible(true);

        $this->assertEquals(
            'http://example.com/path%5Bwith%5D/brackets.jpg',
            $method->invoke($cache, 'http://example.com/path[with]/brackets.jpg')
        );
    }

    public function testEncodeUrlPreservesIpv6HostBrackets()
    {
        $cache = new FileStash(['path' => $this->cachePath]);
        $method = new ReflectionMethod(FileStash::class, 'encodeUrl');
        $method->setAccessible(true);

        $this->assertEquals(
            'http://[::1]/path%5Bwith%5D/brackets.jpg',
            $method->invoke($cache, 'http://[::1]/path[with]/brackets.jpg')
        );
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
        $this->app['files']->put("{$this->diskPath}/test-image.jpg", 'abc');

        // Create 5 files but set chunk size to 2
        $files = [];
        for ($i = 0; $i < 5; $i++) {
            $files[] = new GenericFile('test://test-image.jpg');
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
            return $receivedPaths;
        });

        $this->assertTrue($callbackCalled);
        $this->assertCount(5, $paths);
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

        $this->expectException(FailedToRetrieveFileException::class);
        $this->expectExceptionMessage('status code 404');
        $cache->get($file, $this->noop);
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

    public function testAllowedHostsFromEnvString()
    {
        // Simulate env string format (comma-separated)
        $cache = new FileStash([
            'path' => $this->cachePath,
            'allowed_hosts' => 'example.com, cdn.example.com, *.trusted.com',
        ]);

        $method = new ReflectionMethod(FileStash::class, 'validateHost');
        $method->setAccessible(true);

        // Should not throw for allowed hosts
        $method->invoke($cache, 'https://example.com/image.jpg');
        $method->invoke($cache, 'https://cdn.example.com/image.jpg');
        $method->invoke($cache, 'https://sub.trusted.com/image.jpg');

        // Should throw for disallowed host
        $this->expectException(\Jackardios\FileStash\Exceptions\HostNotAllowedException::class);
        $method->invoke($cache, 'https://evil.com/image.jpg');
    }

    public function testAllowedHostsEmptyAllowsAll()
    {
        $cache = new FileStash([
            'path' => $this->cachePath,
            'allowed_hosts' => [], // Empty array should allow all
        ]);

        $method = new ReflectionMethod(FileStash::class, 'validateHost');
        $method->setAccessible(true);

        // Should allow any host when allowed_hosts is empty
        $this->assertNull($method->invoke($cache, 'https://any-domain.com/image.jpg'));
    }

    public function testLifecycleLockPathUsesNormalizedCachePath()
    {
        $cacheWithPlainPath = new FileStash(['path' => $this->cachePath]);
        $cacheWithTrailingSlash = new FileStash(['path' => $this->cachePath . '/']);

        $method = new ReflectionMethod(FileStash::class, 'getLifecycleLockPath');
        $method->setAccessible(true);

        $this->assertSame(
            $method->invoke($cacheWithPlainPath),
            $method->invoke($cacheWithTrailingSlash)
        );
    }

    public function testAllowedHostsThrowsOnEmptyHost()
    {
        $cache = new FileStash([
            'path' => $this->cachePath,
            'allowed_hosts' => ['example.com'],
        ]);

        $method = new ReflectionMethod(FileStash::class, 'validateHost');
        $method->setAccessible(true);

        $this->expectException(\Jackardios\FileStash\Exceptions\HostNotAllowedException::class);
        $this->expectExceptionMessage('(empty)');
        $method->invoke($cache, '/no-host-url');
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

        $this->expectException(\Jackardios\FileStash\Exceptions\HostNotAllowedException::class);
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

        $this->expectException(\Jackardios\FileStash\Exceptions\HostNotAllowedException::class);
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
        $cache = new class(['path' => $this->cachePath]) extends FileStash {
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
        $nonExistentPath = sys_get_temp_dir() . '/non_existent_path_' . uniqid();
        $cache = new FileStash(['path' => $nonExistentPath]);

        $this->assertSame(['deleted' => 0, 'remaining' => 0, 'total_size' => 0, 'completed' => true], $cache->prune());
    }

    public function testClearOnNonExistentPath()
    {
        $nonExistentPath = sys_get_temp_dir() . '/non_existent_path_' . uniqid();
        $cache = new FileStash(['path' => $nonExistentPath]);

        $this->assertDirectoryDoesNotExist($nonExistentPath);
        $cache->clear();
        $this->assertDirectoryDoesNotExist($nonExistentPath);
    }

    public function testRetrieveCreatesPathIfNotExists()
    {
        $newPath = sys_get_temp_dir() . '/new_cache_path_' . uniqid();
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
        $cache = new class(['path' => $this->cachePath, 'max_age' => 1, 'prune_timeout' => 300]) extends FileStash {
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
    }

    public function testGuzzleClientHasUserAgent()
    {
        $cache = $this->createCache(['user_agent' => 'TestAgent/1.0']);
        $reflection = new \ReflectionProperty($cache, 'client');
        $reflection->setAccessible(true);
        $client = $reflection->getValue($cache);

        $config = $client->getConfig();
        $this->assertArrayHasKey('headers', $config);
        $this->assertEquals('TestAgent/1.0', $config['headers']['User-Agent']);
    }

    public function testGuzzleClientHasMaxRedirects()
    {
        $cache = $this->createCache(['max_redirects' => 3]);
        $reflection = new \ReflectionProperty($cache, 'client');
        $reflection->setAccessible(true);
        $client = $reflection->getValue($cache);

        $config = $client->getConfig();
        $this->assertArrayHasKey('allow_redirects', $config);
        $this->assertEquals(3, $config['allow_redirects']['max']);
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
        $dispatcher = $this->createMock(Dispatcher::class);
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
        $dispatcher = $this->createMock(Dispatcher::class);
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

        $pruneEvents = array_filter($dispatched, fn($e) => $e instanceof CachePruneCompleted);
        $this->assertCount(1, $pruneEvents);
        $pruneEvent = array_values($pruneEvents)[0];
        $this->assertTrue($pruneEvent->completed);
    }

    public function testEvictionEventDispatchedOnClear()
    {
        $dispatched = [];
        $dispatcher = $this->createMock(Dispatcher::class);
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

        $evictionEvents = array_filter($dispatched, fn($e) => $e instanceof CacheFileEvicted);
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

    public function testMetricsReset()
    {
        $metrics = new CacheMetrics();
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
        // Set atime to now (within 300s interval)
        touch($cachedPath);
        clearstatcache();
        $atimeBefore = fileatime($cachedPath);

        // Sleep briefly to ensure we'd see a time difference if touch happened
        usleep(1100000); // 1.1 seconds

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

    public function testUrlEncodingWithCyrillic()
    {
        $cache = $this->createCache();
        $method = new ReflectionMethod($cache, 'encodeUrl');
        $method->setAccessible(true);

        $result = $method->invoke($cache, 'https://example.com/файл.jpg');
        $this->assertStringContainsString('example.com/', $result);
        // Cyrillic characters should be percent-encoded
        $this->assertStringNotContainsString('файл', $result);
        $this->assertStringContainsString('.jpg', $result);
    }

    public function testUrlEncodingPreservesAlreadyEncodedSequences()
    {
        $cache = $this->createCache();
        $method = new ReflectionMethod($cache, 'encodeUrl');
        $method->setAccessible(true);

        // Already-encoded %20 should be preserved
        $result = $method->invoke($cache, 'https://example.com/path%20with%20spaces/file.jpg');
        $this->assertStringContainsString('%20', $result);
    }

    public function testUrlEncodingWithCjkCharacters()
    {
        $cache = $this->createCache();
        $method = new ReflectionMethod($cache, 'encodeUrl');
        $method->setAccessible(true);

        $result = $method->invoke($cache, 'https://example.com/图片.jpg');
        $this->assertStringNotContainsString('图片', $result);
        $this->assertStringContainsString('.jpg', $result);
    }

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
        $dispatcher = $this->createMock(Dispatcher::class);
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

        $evictions = array_filter($dispatched, fn($e) => $e instanceof CacheFileEvicted);
        $this->assertGreaterThanOrEqual(1, count($evictions));
        $eviction = array_values($evictions)[0];
        $this->assertEquals('pruned_age', $eviction->reason);
    }

    public function testForgetEvictionEventDispatched()
    {
        $dispatched = [];
        $dispatcher = $this->createMock(Dispatcher::class);
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

        $evictions = array_filter($dispatched, fn($e) => $e instanceof CacheFileEvicted);
        $this->assertCount(1, $evictions);
    }

    public function testFileStashFakeMetrics()
    {
        $fake = new FileStashFake($this->app);
        $metrics = $fake->metrics();
        $this->assertInstanceOf(CacheMetrics::class, $metrics);
        $this->assertEquals(0, $metrics->hits);
    }
}
