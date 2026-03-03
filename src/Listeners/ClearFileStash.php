<?php

namespace Jackardios\FileStash\Listeners;

use Jackardios\FileStash\Contracts\FileStash as FileStashContract;

class ClearFileStash
{
    public function __construct(protected FileStashContract $cache)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(): void
    {
        $this->cache->clear();
    }
}
