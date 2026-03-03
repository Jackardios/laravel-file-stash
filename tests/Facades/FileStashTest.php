<?php

namespace Jackardios\FileStash\Tests\Facades;

use Jackardios\FileStash\Facades\FileStash as FileStashFacade;
use Jackardios\FileStash\FileStash as BaseFileStash;
use Jackardios\FileStash\GenericFile;
use Jackardios\FileStash\Tests\TestCase;
use FileStash;

class FileStashTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists(FileStash::class)) {
            class_alias(FileStashFacade::class, 'FileStash');
        }
    }

    public function testFacade()
    {
        $this->assertInstanceOf(BaseFileStash::class, FileStash::getFacadeRoot());
    }

    public function testFake()
    {
        FileStash::fake();
        $file = new GenericFile('https://example.com/image.jpg');
        $path = FileStash::get($file, function ($file, $path) {
            return $path;
        });

        $this->assertFalse($this->app['files']->exists($path));
    }
}
