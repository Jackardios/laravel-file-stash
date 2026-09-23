<?php

namespace Jackardios\FileStash\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Jackardios\FileStash\FileStashServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    /**
     * Per-test storage directory, so the fake and the default cache path
     * never write into vendor/ and parallel runs never share state.
     */
    protected string $storagePath;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir().'/file_stash_storage_'.bin2hex(random_bytes(8));

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem)->deleteDirectory($this->storagePath);
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [FileStashServiceProvider::class];
    }

    /**
     * @return Application
     */
    protected function resolveApplication()
    {
        return parent::resolveApplication()->useStoragePath($this->storagePath);
    }
}
