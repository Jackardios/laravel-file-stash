<?php

namespace Jackardios\FileStash\Tests;

use Jackardios\FileStash\FileStashServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    /**
      * Boots the application.
      *
      * @return \Illuminate\Foundation\Application
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
