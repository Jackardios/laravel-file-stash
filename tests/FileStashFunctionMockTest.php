<?php

namespace Jackardios\FileStash\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Jackardios\FileStash\Exceptions\FailedToRetrieveFileException;
use Jackardios\FileStash\Exceptions\LifecycleLockTimeoutException;
use Jackardios\FileStash\Exceptions\SourceResourceIsInvalidException;
use Jackardios\FileStash\Exceptions\SourceResourceTimedOutException;
use Jackardios\FileStash\FileStash;
use Jackardios\FileStash\GenericFile;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->cachePath = sys_get_temp_dir().'/file_stash_test_'.bin2hex(random_bytes(8));
        $this->diskPath = sys_get_temp_dir().'/file_stash_disk_'.bin2hex(random_bytes(8));
        $this->files = new Filesystem;
        $this->noop = fn ($file, $path) => $path;

        $this->files->makeDirectory($this->cachePath, 0755, false, true);
        $this->files->makeDirectory($this->diskPath, 0755, false, true);
    }

    protected function tearDown(): void
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
        $filesystemMock = $this->createStub(FilesystemAdapter::class);
        $filesystemMock->method('readStream')->willReturn($stream);
        $filesystemMock->method('getDriver')->willReturn($filesystemMock);
        $filesystemMock->method('get')->willReturn($filesystemMock);

        $filesystemManagerMock = $this->createStub(FilesystemManager::class);
        $filesystemManagerMock->method('disk')->willReturn($filesystemMock);

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

        $filesystemMock = $this->createStub(FilesystemAdapter::class);
        $filesystemMock->method('readStream')->willReturnCallback(function ($path) use ($fixturesPath) {
            $fullPath = $fixturesPath.'/'.$path;
            if (! file_exists($fullPath)) {
                return null;
            }

            return fopen($fullPath, 'rb');
        });
        $filesystemMock->method('exists')->willReturnCallback(function ($path) use ($fixturesPath) {
            return file_exists($fixturesPath.'/'.$path);
        });
        $filesystemMock->method('getDriver')->willReturn($filesystemMock);
        $filesystemMock->method('get')->willReturn($filesystemMock);

        $filesystemManagerMock = $this->createStub(FilesystemManager::class);
        $filesystemManagerMock->method('disk')->willReturn($filesystemMock);

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

        $filesystemManagerMock = $this->createStub(FilesystemManager::class);

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
        return "{$this->cachePath}/".hash('sha256', $url);
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

    public function testDiskSourceEndingBeforeEofIsRejected()
    {
        $url = 's3://files/test-image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'some data');
        rewind($stream);

        $cache = $this->createCacheWithMockS3($stream);

        // The source died mid-transfer: a short copy count with EOF never
        // reached must not pass as a complete file.
        $streamCopyMock = $this->getFunctionMock('Jackardios\\FileStash', 'stream_copy_to_stream');
        $streamCopyMock->expects($this->once())->willReturn(4);
        $feofMock = $this->getFunctionMock('Jackardios\\FileStash', 'feof');
        $feofMock->expects($this->once())->willReturn(false);

        $this->expectException(SourceResourceIsInvalidException::class);
        $this->expectExceptionMessage('ended before EOF');

        try {
            $cache->get($file, $this->noop);
        } finally {
            $this->assertFileDoesNotExist($cachedPath);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testDiskCopyFailedFlushAborts()
    {
        $url = 's3://files/test-image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'some data');
        rewind($stream);

        $cache = $this->createCacheWithMockS3($stream);

        // A failed flush means part of the payload never reached the file
        // (e.g. ENOSPC) — the copy must not pass as complete.
        $fflushMock = $this->getFunctionMock('Jackardios\\FileStash', 'fflush');
        $fflushMock->expects($this->once())->willReturn(false);

        $this->expectException(FailedToRetrieveFileException::class);
        $this->expectExceptionMessage('flush');

        try {
            $cache->get($file, $this->noop);
        } finally {
            $this->assertFileDoesNotExist($cachedPath);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testFsyncFailureAbortsPublish()
    {
        $url = 'https://files/image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $cache = $this->createCacheWithMockClient([
            new Response(200, [], 'payload'),
        ]);

        $fsyncMock = $this->getFunctionMock('Jackardios\\FileStash', 'fsync');
        $fsyncMock->expects($this->once())->willReturn(false);

        $this->expectException(FailedToRetrieveFileException::class);
        $this->expectExceptionMessage('fsync');

        try {
            $cache->get($file, $this->noop);
        } finally {
            $this->assertFileDoesNotExist($cachedPath);
            $this->assertSame([], glob($this->cachePath.'/*.tmp') ?: []);
        }
    }

    public function testTempLockFailureRetriesWithFreshTemp()
    {
        $url = 'https://files/image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $cache = $this->createCacheWithMockClient([
            new Response(200, [], 'payload'),
        ]);

        // The first exclusive lock on the fresh temp file fails (e.g. EINTR):
        // the temp must be discarded and the attempt repeated.
        $failed = false;
        $flockMock = $this->getFunctionMock('Jackardios\\FileStash', 'flock');
        $flockMock->expects($this->atLeast(3))->willReturnCallback(function ($stream, $operation) use (&$failed) {
            if ($operation === LOCK_EX && ! $failed) {
                $failed = true;

                return false;
            }

            return \flock($stream, $operation);
        });

        $path = $cache->get($file, $this->noop);

        $this->assertSame($cachedPath, $path);
        $this->assertSame('payload', file_get_contents($cachedPath));
        $this->assertSame([], glob($this->cachePath.'/*.tmp') ?: []);
    }

    public function testLostSharedConversionRedownloadsUnderSameClaim()
    {
        $url = 'https://files/image.jpg';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $cache = $this->createCacheWithMockClient([
            new Response(200, [], 'first attempt'),
            new Response(200, [], 'second attempt'),
        ]);

        // The non-atomic EX→SH conversion lost the lock entirely: the stream
        // is unprotected, so the download must be repeated under the claim.
        $conversionFailed = false;
        $flockMock = $this->getFunctionMock('Jackardios\\FileStash', 'flock');
        $flockMock->expects($this->atLeast(4))->willReturnCallback(function ($stream, $operation) use (&$conversionFailed) {
            if ($operation === LOCK_SH && ! $conversionFailed) {
                $conversionFailed = true;

                return false;
            }

            return \flock($stream, $operation);
        });

        $path = $cache->get($file, $this->noop);

        $this->assertSame($cachedPath, $path);
        $this->assertSame('second attempt', file_get_contents($cachedPath));
        $this->assertSame([], glob($this->cachePath.'/*.tmp') ?: []);
    }

    public function testClaimLockWaitBudgetIsSharedAcrossClaimRetries()
    {
        $url = 'https://files/image.jpg';
        $file = new GenericFile($url);
        $claimPath = $this->cachePath.'/.locks/'.hash('sha256', $url).'.lock';

        $cache = $this->createCacheWithMockClient([], [
            'lock_wait_timeout' => 0.2,
            'lock_max_attempts' => 1,
        ]);

        // Each acquisition round: contended for ~150 ms, then "acquired" — but
        // the claim file is unlinked first, so the caller sees nlink == 0 and
        // retries. Without a shared deadline each of the five inner retries
        // would get a fresh 0.2 s budget (~0.75 s total here).
        $roundStart = null;
        $flockMock = $this->getFunctionMock('Jackardios\\FileStash\\Support', 'flock');
        $flockMock->expects($this->atLeastOnce())->willReturnCallback(function ($stream, $operation) use (&$roundStart, $claimPath) {
            if (($operation & LOCK_EX) === 0) {
                return \flock($stream, $operation);
            }

            $roundStart ??= microtime(true);
            if (microtime(true) - $roundStart < 0.15) {
                return false;
            }

            $roundStart = null;
            @unlink($claimPath);

            return \flock($stream, $operation);
        });

        $start = microtime(true);

        try {
            $cache->get($file, $this->noop);
            $this->fail('Expected FailedToRetrieveFileException to be thrown.');
        } catch (FailedToRetrieveFileException) {
            // The claim could never be secured within the budget.
        }

        $this->assertLessThan(
            0.5,
            microtime(true) - $start,
            'Claim retries must share one lock_wait_timeout budget.'
        );
    }

    public function testRetrieveThrowsFailedToRetrieveFileExceptionAfterMaxAttempts()
    {
        $url = 'fixtures://test-file.txt';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $cache = $this->createCacheWithMockFixtures();

        // Simulate an unusable cache directory: neither published entries nor
        // download claims can be opened, so every retrieve attempt fails.
        $fopenMock = $this->getFunctionMock('Jackardios\\FileStash', 'fopen');
        $fopenMock->expects($this->atLeast(3))
            ->willReturnCallback(function ($path, $mode) use ($cachedPath) {
                if ($path === $cachedPath && $mode === 'rb') {
                    return false;
                }
                if (str_ends_with($path, '.lock') && $mode === 'c') {
                    return false;
                }

                return \fopen($path, $mode);
            });

        $this->expectException(FailedToRetrieveFileException::class);
        $this->expectExceptionMessage('Failed to retrieve file after 3 attempts');

        try {
            $cache->get($file, $this->noop);
        } finally {
            $this->assertFileDoesNotExist($cachedPath);
        }
    }

    public function testGetWaitsForLockReleaseWhenNotThrowing()
    {
        $url = 'fixtures://test-file.txt';
        $file = new GenericFile($url);
        $cachedPath = $this->getCachedPath($url);

        $cache = $this->createCacheWithMockFixtures();

        // Simulate: Another process already wrote the file and holds LOCK_EX
        copy(__DIR__.'/files/test-file.txt', $cachedPath);
        $writingProcessHandle = fopen($cachedPath, 'rb+');
        $this->assertIsResource($writingProcessHandle, 'Failed to open handle for writing process simulation.');
        $this->assertTrue(flock($writingProcessHandle, LOCK_EX), 'Failed to acquire LOCK_EX for writing simulation.');

        // Mock flock to simulate waiting for lock release. The wait loop lives
        // in LockManager, so the mocks target the Support namespace.
        $flockMock = $this->getFunctionMock('Jackardios\\FileStash\\Support', 'flock');
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

        $usleepMock = $this->getFunctionMock('Jackardios\\FileStash\\Support', 'usleep');
        $usleepMock->expects($this->exactly($maxAttemptsBeforeSuccess));

        $resultPath = $cache->get($file, $this->noop, false);

        $this->assertEquals($cachedPath, $resultPath);
        $this->assertFileExists($cachedPath);
        $this->assertGreaterThanOrEqual($maxAttemptsBeforeSuccess, $lockAttempt);
        $this->assertGreaterThan(0, filesize($cachedPath));
        // Verify the file was read from cache, not re-downloaded
        $this->assertStringEqualsFile($cachedPath, file_get_contents(__DIR__.'/files/test-file.txt'));

        if (is_resource($writingProcessHandle)) {
            \flock($writingProcessHandle, LOCK_UN);
            fclose($writingProcessHandle);
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
                if (! $callbackStarted && str_starts_with($uri, $cachePath.'/')) {
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
                if (! $callbackStarted && str_starts_with($uri, $cachePath.'/')) {
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

        // The lifecycle lock acquisition lives in LockManager (Support namespace).
        $flockMock = $this->getFunctionMock('Jackardios\\FileStash\\Support', 'flock');
        $flockMock->expects($this->atLeastOnce())->willReturn(false);

        $this->expectException(LifecycleLockTimeoutException::class);
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
        $timeMock->expects($this->atLeastOnce())->willReturnCallback(function () use (&$callCount, $baseTime) {
            $callCount++;
            // First 3 calls return base time (for startTime and initial checks)
            // After that, return base time + timeout + 1 to trigger timeout
            if ($callCount <= 3) {
                return $baseTime;
            }

            return $baseTime + 10; // 10 seconds later, exceeds 1 second timeout
        });

        $filesystemManagerMock = $this->createStub(FilesystemManager::class);

        $cache = new FileStash([
            'path' => $this->cachePath,
            'max_age' => 1, // 1 minute
            'prune_timeout' => 1, // 1 second timeout
        ], null, $this->files, $filesystemManagerMock);

        $stats = $cache->prune();

        // Prune should report incomplete due to timeout
        $this->assertFalse($stats['completed']);

        // Some files should still exist due to timeout
        $remainingFiles = glob("{$this->cachePath}/file_*");
        $this->assertNotEmpty($remainingFiles, 'Some files should remain after prune timeout');
    }

    public function testDownloadRetriesWhenEntryLostInConversionWindow()
    {
        // The EX→SH conversion after publish is not atomic on Linux: prune can
        // unlink the freshly renamed entry in between. The writer detects this
        // via the nlink recheck and repeats the download under the same claim.
        $cache = $this->createCacheWithMockClient([
            new Response(200, ['Content-Length' => '5'], 'abcde'),
            new Response(200, ['Content-Length' => '5'], 'abcde'),
        ]);

        $renameCalls = 0;
        $renameMock = $this->getFunctionMock('Jackardios\\FileStash', 'rename');
        $renameMock->expects($this->exactly(2))->willReturnCallback(
            function (string $from, string $to) use (&$renameCalls): bool {
                $renameCalls++;
                $result = \rename($from, $to);

                if ($renameCalls === 1) {
                    // Simulate prune winning the conversion window.
                    \unlink($to);
                }

                return $result;
            }
        );

        $file = new GenericFile('https://example.com/window.bin');
        $result = $cache->get($file, fn ($f, $path) => file_get_contents($path));

        $this->assertSame('abcde', $result);
        $this->assertFileExists($this->getCachedPath('https://example.com/window.bin'));
        $this->assertSame([], glob("{$this->cachePath}/*.tmp"));
    }

    public function testForgetSkipsEntryRepublishedDuringDelete()
    {
        // deleteEntry() locks the inode it opened, but unlink() acts on the
        // path. If a writer atomically re-publishes the entry in between, the
        // dev/ino comparison must detect it and skip the deletion.
        $cache = $this->createCacheWithMockClient([
            new Response(200, ['Content-Length' => '5'], 'abcde'),
        ]);

        $file = new GenericFile('https://example.com/inode.bin');
        $cache->get($file, fn ($f, $path) => $path);

        $cachedPath = $this->getCachedPath('https://example.com/inode.bin');
        $this->assertFileExists($cachedPath);

        $statMock = $this->getFunctionMock('Jackardios\\FileStash', 'stat');
        $statMock->expects($this->once())->willReturnCallback(
            function (string $path) {
                $real = \stat($path);
                if (is_array($real)) {
                    // Pretend the path now points at a different inode.
                    $real['ino'] = ((int) $real['ino']) + 1;
                }

                return $real;
            }
        );

        $this->assertFalse($cache->forget($file));
        $this->assertFileExists($cachedPath);
    }
}
