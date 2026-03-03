<?php

namespace Jackardios\FileStash\Events;

use Jackardios\FileStash\Contracts\File;

class CacheMiss
{
    public function __construct(
        public readonly File $file,
        public readonly string $url
    ) {
    }
}
