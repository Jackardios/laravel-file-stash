<?php

namespace Jackardios\FileStash\Tests\Facades;

use FileStash;
use Jackardios\FileStash\Facades\FileStash as FileStashFacade;
use Jackardios\FileStash\FileStash as BaseFileStash;
use Jackardios\FileStash\GenericFile;
use Jackardios\FileStash\Tests\TestCase;

class FileStashTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(FileStash::class)) {
            class_alias(FileStashFacade::class, 'FileStash');
        }
    }

    public function testFacade()
    {
        $this->assertInstanceOf(BaseFileStash::class, FileStash::getFacadeRoot());
    }

    public function testFake()
    {
        $fake = FileStash::fake();
        $file = new GenericFile('https://example.com/image.jpg');
        $path = FileStash::get($file, function ($file, $path) {
            return $path;
        });

        // The fake creates real files so callbacks can read them.
        $this->assertTrue($this->app['files']->exists($path));
        $fake->assertRetrieved('https://example.com/image.jpg');
    }
}
