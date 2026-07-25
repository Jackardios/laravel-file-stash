<?php

namespace Jackardios\FileStash\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Jackardios\FileStash\FileStashServiceProvider;

class TestCase extends BaseTestCase
{
    /**
     * Boots the application.
     *
     * @return Application
     */
    public function createApplication()
    {
        // We create a full Laravel app here for testing purposes. The FileStash
        // needs access to the application config and the filesystem singleton.
        $app = require __DIR__.'/../vendor/laravel/laravel/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app->register(FileStashServiceProvider::class);

        return $app;
    }
}
