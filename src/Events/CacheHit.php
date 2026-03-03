<?php

namespace Jackardios\FileStash\Events;

use Jackardios\FileStash\Contracts\File;

class CacheHit
{
    public function __construct(
        public readonly File $file,
        public readonly string $cachedPath
    ) {
    }
}
