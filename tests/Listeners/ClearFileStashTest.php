<?php

namespace Jackardios\FileStash\Tests\Listeners;

use Illuminate\Support\Facades\Log;
use Jackardios\FileStash\Tests\TestCase;

class ClearFileStashTest extends TestCase
{
    protected string $cachePath;

    protected string $entryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir().'/file_stash_test_listener_'.bin2hex(random_bytes(8));
        $this->entryPath = $this->cachePath.'/'.hash('sha256', 'https://example.com/file.txt');
        $this->app['files']->makeDirectory($this->cachePath, 0755, false, true);
        $this->app['files']->put($this->entryPath, 'abc');
        config(['file-stash.path' => $this->cachePath]);
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->cachePath);
        parent::tearDown();
    }

    public function testCacheClearClearsTheStash()
    {
        $this->artisan('cache:clear')->assertExitCode(0);

        $this->assertFileDoesNotExist($this->entryPath);
    }

    public function testTagScopedCacheClearKeepsTheStash()
    {
        // `cache:clear --tags=...` flushes only the tagged items of a store;
        // it must not wipe unrelated caches.
        $this->app['events']->dispatch('cache:clearing', [null, ['reports']]);

        $this->assertFileExists($this->entryPath);
    }

    public function testLifecycleLockTimeoutDoesNotAbortCacheClear()
    {
        config(['file-stash.lifecycle_lock_timeout' => 0.05]);
        Log::spy();

        // Another process is inside clear() (or holds the lock for another
        // reason): cache:clear must still flush the application cache.
        $lock = fopen($this->cachePath.'/.lifecycle.lock', 'c+');
        $this->assertTrue(flock($lock, LOCK_EX));

        try {
            $this->artisan('cache:clear')->assertExitCode(0);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertFileExists($this->entryPath);
        Log::shouldHaveReceived('warning')->once();
    }
}
