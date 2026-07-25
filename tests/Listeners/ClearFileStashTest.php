<?php

namespace Jackardios\FileStash\Tests\Listeners;

use Jackardios\FileStash\Tests\TestCase;

class ClearFileStashTest extends TestCase
{
    protected string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir().'/file_stash_test_listener_'.uniqid('', true);
        $this->app['files']->makeDirectory($this->cachePath, 0755, false, true);
    }

    protected function tearDown(): void
    {
        if ($this->app['files']->exists($this->cachePath)) {
            $this->app['files']->deleteDirectory($this->cachePath);
        }
        parent::tearDown();
    }

    public function testListen()
    {
        config(['file-stash.path' => $this->cachePath]);
        $this->app['files']->put($this->cachePath.'/1', 'abc');
        $this->app['events']->dispatch('cache:clearing');
        $this->assertFalse($this->app['files']->exists($this->cachePath.'/1'));
    }
}
