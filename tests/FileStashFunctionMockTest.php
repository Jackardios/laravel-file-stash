<?php

namespace Jackardios\FileStash\Tests;

use Jackardios\FileStash\Exceptions\FailedToRetrieveFileException;
use Jackardios\FileStash\Exceptions\SourceResourceIsInvalidException;
use Jackardios\FileStash\Exceptions\SourceResourceTimedOutException;
use Jackardios\FileStash\FileStash;
use Jackardios\FileStash\GenericFile;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Tests that require PHP function mocking via PHPMock.
 *
 * These tests use plain PHPUnit TestCase (not Laravel's) because
 * Laravel's TestCase + @runTestsInSeparateProcesses causes crashes
 * on PHP 8.3+ with Laravel 10 due to error handler conflicts.
 *
 * @see https://github.com/laravel/framework/issues/49593
 */
#[RunTestsInSeparateProcesses]
class FileStashFunctionMockTest extends TestCase
{
    use PHPMock;

    protected string $cachePath;
    protected string $diskPath;
    protected Filesystem $files;
    protected \Closure $noop;

    public function setUp(): void
    {
        parent::setUp();

        $this->cachePath = sys_get_temp_dir().'/biigle_file_cache_test_'.uniqid();
        $this->diskPath = sys_get_temp_dir().'/biigle_file_cache_disk_'.uniqid();
        $this->files = new Filesystem();
        $this->noop = fn ($file, $path) => $path;

        $this->files->makeDirectory($this->cachePath, 0755, false, true);
        $this->files->makeDirectory($this->diskPath, 0755, false, true);
    }

    public function tearDown(): void
    {
        $this->files->deleteDirectory($this->cachePath);
        $this->files->deleteDirectory($this->diskPath);
        parent::tearDown();
    }

    /**
     * Create a FileStash with a mock S3 filesystem.
     */
    protected function createCacheWithMockS3($stream, array $config = []): FileStash
    {
        $filesystemMock = $this->createMock(FilesystemAdapter::class);
        $filesystemMock->method('readStream')->willReturn($stream);
        $filesystemMock->method('getDriver')->willReturn($filesystemMock);
        $filesystemMock->method('get')->willReturn($filesystemMock);

        $filesystemManagerMock = $this->createMock(FilesystemManager::class);
        $filesystemManagerMock->method('disk')->with('s3')->willReturn($filesystemMock);

        return new FileStash(
            array_merge(['path' => $this->cachePath], $config),
            null,
            $this->files,
            $filesystemManagerMock
        );
    }

    /**
     * Create a FileStash with a mock fixtures disk.
     */
    protected function createCacheWithMockFixtures(array $config = []): FileStash
    {
        $fixturesPath = __DIR__.'/files';

        $filesystemMock = $this->createMock(FilesystemAdapter::class);
        $filesystemMock->method('readStream')->willReturnCallback(function ($path) use ($fixturesPath) {
            $fullPath = $fixturesPath . '/' . $path;
            if (!file_exists($fullPath)) {
                return null;
            }
            return fopen($fullPath, 'rb');
        });
        $filesystemMock->method('exists')->willReturnCallback(function ($path) use ($fixturesPath) {
            return file_exists($fixturesPath . '/' . $path);
        });
        $filesystemMock->method('getDriver')->willReturn($filesystemMock);
        $filesystemMock->method('get')->willReturn($filesystemMock);

        $filesystemManagerMock = $this->createMock(FilesystemManager::class);
        $filesystemManagerMock->method('disk')->with('fixtures')->willReturn($filesystemMock);

        return new FileStash(
            array_merge(['path' => $this->cachePath], $config),
            null,
            $this->files,
            $filesystemManagerMock
        );
    }

    /**
     * Create a FileStash with a mock HTTP client.
     */
    protected function createCacheWithMockClient(array $responses, array $config = []): FileStash
    {
        $mock = new MockHandler($responses);
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);

        $filesystemManagerMock = $this->createMock(FilesystemManager::class);

        return new FileStash(
            array_merge(['path' => $this->cachePath], $config),
            $client,
            $this->files,
            $filesystemManagerMock
        );
    }

    /**
     * Get the cached path for a file URL.
     */
    protected function getCachedPath(string $url): string
    {
        return "{$this->cachePath}/" . hash('sha256', $url);
    }

    public function testGetWithUnlimitedReadTimeoutDoesNotForceZeroSecondStreamTimeout()
    {
        $url = 'fixtures://test-file.txt';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $cache = $this->createCacheWithMockFixtures(['read_timeout' => -1]);

        $streamSetTimeoutMock = $this->getFunctionMock('Jackardios\\FileStash', 'stream_set_timeout');
        $streamSetTimeoutMock->expects($this->never());

        $path = $cache->get($file, $this->noop);
        $this->assertEquals($cachedPath, $path);
        $this->assertFileExists($cachedPath);
    }

    public function testGetPreservesFractionalReadTimeoutPrecision()
    {
        $cache = $this->createCacheWithMockFixtures(['read_timeout' => 0.5]);
        $file = new GenericFile('fixtures://test-file.txt');

        $streamSetTimeoutMock = $this->getFunctionMock('Jackardios\\FileStash', 'stream_set_timeout');
        $streamSetTimeoutMock->expects($this->once())
            ->willReturnCallback(function ($stream, $seconds, $microseconds = 0) {
                $this->assertIsResource($stream);
                $this->assertSame(0, $seconds);
                $this->assertSame(500000, $microseconds);
                return true;
            });

        $path = $cache->get($file, $this->noop);
        $this->assertFileExists($path);
    }

    public function testGetDiskThrowsSourceResourceTimeoutException()
    {
        $url = 's3://files/test-image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'some data');
        rewind($stream);

        $cache = $this->createCacheWithMockS3($stream, ['read_timeout' => 0.1]);

        $streamGetMetaDataMock = $this->getFunctionMock('Jackardios\\FileStash', 'stream_get_meta_data');
        $streamGetMetaDataMock->expects($this->atLeastOnce())->willReturn(['timed_out' => true]);

        $this->expectException(SourceResourceTimedOutException::class);

        try {
            $cache->get($file, $this->noop);
        } finally {
            $this->assertFileDoesNotExist($cachedPath);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testGetDiskThrowsSourceResourceInvalidExceptionOnCopyFail()
    {
        $url = 's3://files/test-image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'some data');
        rewind($stream);

        $cache = $this->createCacheWithMockS3($stream);

        $streamCopyMock = $this->getFunctionMock('Jackardios\\FileStash', 'stream_copy_to_stream');
        $streamCopyMock->expects($this->once())->willReturn(false);

        $this->expectException(SourceResourceIsInvalidException::class);
        $this->expectExceptionMessage('Failed to copy stream data');

        try {
            $cache->get($file, $this->noop);
        } finally {
            $this->assertFileDoesNotExist($cachedPath);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testRetrieveThrowsFailedToRetrieveFileExceptionAfterMaxAttempts()
    {
        $url = 'fixtures://test-file.txt';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $cache = $this->createCacheWithMockFixtures();

        touch($cachedPath);
        $this->assertFileExists($cachedPath);

        $fopenMock = $this->getFunctionMock('Jackardios\\FileStash', 'fopen');
        $fopenMock->expects($this->atLeast(3))
            ->willReturnCallback(function ($path, $mode) use ($cachedPath) {
                if ($path === $cachedPath) {
                    if ($mode === 'xb+') {
                        return \fopen($path, $mode);
                    }
                    if ($mode === 'rb') {
                        return false;
                    }
                }
                return \fopen($path, $mode);
            });

        $usleepMock = $this->getFunctionMock('Jackardios\\FileStash', 'usleep');
        $usleepMock->expects($this->any());

        $this->expectException(FailedToRetrieveFileException::class);
        $this->expectExceptionMessage('Failed to retrieve file after 3 attempts');

        try {
            $cache->get($file, $this->noop);
        } catch (FailedToRetrieveFileException $e) {
            $this->assertFileExists($cachedPath);
            throw $e;
        } finally {
            if (file_exists($cachedPath)) {
                unlink($cachedPath);
            }
        }
    }

    public function testGetWaitsForLockReleaseWhenNotThrowing()
    {
        $url = 'fixtures://test-file.txt';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $cache = $this->createCacheWithMockFixtures();

        // Simulate: Another process started recording and set LOCK_EX
        $this->assertTrue(touch($cachedPath), "Failed to create cache file for locking.");
        $writingProcessHandle = fopen($cachedPath, 'rb+');
        $this->assertIsResource($writingProcessHandle, "Failed to open handle for writing process simulation.");
        $this->assertTrue(flock($writingProcessHandle, LOCK_EX), "Failed to acquire LOCK_EX for writing simulation.");

        // Mock flock to simulate waiting for lock release
        $flockMock = $this->getFunctionMock('Jackardios\\FileStash', 'flock');
        $lockAttempt = 0;
        $maxAttemptsBeforeSuccess = 3;
        $sharedLockHandle = null;

        $flockMock->expects($this->atLeast($maxAttemptsBeforeSuccess + 1))
            ->willReturnCallback(
                function ($handle, $operation) use (&$lockAttempt, $maxAttemptsBeforeSuccess, &$writingProcessHandle, $cachedPath, &$sharedLockHandle) {
                    $meta = stream_get_meta_data($handle);
                    if ($meta['uri'] === $cachedPath && $meta['mode'] === 'rb') {
                        $sharedLockHandle = $handle;
                    }

                    if ($handle === $sharedLockHandle && ($operation === (LOCK_SH | LOCK_NB) || $operation === LOCK_SH)) {
                        $lockAttempt++;
                        if ($lockAttempt <= $maxAttemptsBeforeSuccess) {
                            return false;
                        }
                        if (is_resource($writingProcessHandle)) {
                            \flock($writingProcessHandle, LOCK_UN);
                            fclose($writingProcessHandle);
                            $writingProcessHandle = null;
                        }
                        return \flock($handle, $operation);
                    }

                    return \flock($handle, $operation);
                }
            );

        $usleepMock = $this->getFunctionMock('Jackardios\\FileStash', 'usleep');
        $usleepMock->expects($this->exactly($maxAttemptsBeforeSuccess));

        $resultPath = $cache->get($file, $this->noop, false);

        $this->assertEquals($cachedPath, $resultPath);
        $this->assertFileExists($cachedPath);
        $this->assertGreaterThanOrEqual($maxAttemptsBeforeSuccess, $lockAttempt);
        $this->assertGreaterThan(0, filesize($cachedPath));

        if (is_resource($writingProcessHandle)) {
            \flock($writingProcessHandle, LOCK_UN);
            fclose($writingProcessHandle);
        }
    }

    public function testGetRemotePartialDownloadTimeout()
    {
        $url = 'https://files.example.com/large-file.zip';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $cache = $this->createCacheWithMockClient(
            [new Response(200, ['Content-Type' => 'application/zip'])],
            ['read_timeout' => 0.1]
        );

        $streamCopyMock = $this->getFunctionMock('Jackardios\\FileStash', 'stream_copy_to_stream');
        $streamCopyMock->expects($this->once())
            ->willReturnCallback(function ($source, $dest, $maxLength) {
                fwrite($dest, 'some partial data');
                return false;
            });

        $streamMetaMock = $this->getFunctionMock('Jackardios\\FileStash', 'stream_get_meta_data');
        $streamMetaMock->expects($this->atLeastOnce())
            ->willReturn(['timed_out' => true]);

        $this->expectException(SourceResourceTimedOutException::class);

        try {
            $cache->get($file, $this->noop);
        } catch (SourceResourceTimedOutException $e) {
            clearstatcache(true, $cachedPath);
            $this->assertFileDoesNotExist($cachedPath);
            throw $e;
        } finally {
            if (file_exists($cachedPath)) {
                unlink($cachedPath);
            }
        }
    }

    public function testBatchChunkingClosesCachedStreamsBeforeCallback()
    {
        $files = [];
        for ($i = 0; $i < 5; $i++) {
            $files[] = new GenericFile('fixtures://test-file.txt');
        }

        $cache = $this->createCacheWithMockFixtures(['batch_chunk_size' => 2]);
        $callbackStarted = false;
        $cacheStreamClosedBeforeCallback = 0;
        $cachePath = $this->cachePath;

        $fcloseMock = $this->getFunctionMock('Jackardios\\FileStash', 'fclose');
        $fcloseMock->expects($this->atLeastOnce())
            ->willReturnCallback(function ($stream) use (&$callbackStarted, &$cacheStreamClosedBeforeCallback, $cachePath) {
                $meta = stream_get_meta_data($stream);
                $uri = $meta['uri'] ?? '';
                if (!$callbackStarted && str_starts_with($uri, $cachePath . '/')) {
                    $cacheStreamClosedBeforeCallback++;
                }

                return \fclose($stream);
            });

        $cache->batch($files, function () use (&$callbackStarted) {
            $callbackStarted = true;
            return null;
        });

        $this->assertGreaterThan(
            0,
            $cacheStreamClosedBeforeCallback,
            'Chunked batch should close cached file streams before entering the callback.'
        );
    }

    public function testBatchOnceChunkingClosesCachedStreamsBeforeCallback()
    {
        $files = [];
        for ($i = 0; $i < 5; $i++) {
            $files[] = new GenericFile('fixtures://test-file.txt');
        }

        $cache = $this->createCacheWithMockFixtures(['batch_chunk_size' => 2]);
        $callbackStarted = false;
        $cacheStreamClosedBeforeCallback = 0;
        $cachePath = $this->cachePath;

        $fcloseMock = $this->getFunctionMock('Jackardios\\FileStash', 'fclose');
        $fcloseMock->expects($this->atLeastOnce())
            ->willReturnCallback(function ($stream) use (&$callbackStarted, &$cacheStreamClosedBeforeCallback, $cachePath) {
                $meta = stream_get_meta_data($stream);
                $uri = $meta['uri'] ?? '';
                if (!$callbackStarted && str_starts_with($uri, $cachePath . '/')) {
                    $cacheStreamClosedBeforeCallback++;
                }

                return \fclose($stream);
            });

        $cache->batchOnce($files, function () use (&$callbackStarted) {
            $callbackStarted = true;
            return null;
        });

        $this->assertGreaterThan(
            0,
            $cacheStreamClosedBeforeCallback,
            'Chunked batchOnce should close cached file streams before entering the callback.'
        );
    }

    public function testLifecycleLockTimeout()
    {
        $cache = $this->createCacheWithMockFixtures([
            'lifecycle_lock_timeout' => 0,
        ]);

        $flockMock = $this->getFunctionMock('Jackardios\\FileStash', 'flock');
        $flockMock->expects($this->atLeastOnce())->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('lifecycle lock');

        $cache->batch([]);
    }

    public function testPruneTimeout()
    {
        // Create several files that should be pruned by age
        for ($i = 0; $i < 5; $i++) {
            $this->files->put("{$this->cachePath}/file_{$i}", str_repeat('x', 100));
            touch("{$this->cachePath}/file_{$i}", time() - 120); // 2 minutes old
        }

        // Mock time() to simulate timeout after a few iterations
        $baseTime = 1000000;
        $timeMock = $this->getFunctionMock('Jackardios\\FileStash', 'time');
        $callCount = 0;
        $timeMock->expects($this->any())->willReturnCallback(function () use (&$callCount, $baseTime) {
            $callCount++;
            // First 3 calls return base time (for startTime and initial checks)
            // After that, return base time + timeout + 1 to trigger timeout
            if ($callCount <= 3) {
                return $baseTime;
            }
            return $baseTime + 10; // 10 seconds later, exceeds 1 second timeout
        });

        $filesystemManagerMock = $this->createMock(FilesystemManager::class);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'max_age' => 1, // 1 minute
            'prune_timeout' => 1, // 1 second timeout
        ], null, $this->files, $filesystemManagerMock);

        $cache->prune();

        // Some files should still exist due to timeout
        $remainingFiles = glob("{$this->cachePath}/file_*");
        $this->assertNotEmpty($remainingFiles, "Some files should remain after prune timeout");
    }
}
