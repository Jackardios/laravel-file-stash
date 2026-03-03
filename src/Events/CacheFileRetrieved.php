<?php

namespace Jackardios\FileStash\Events;

use Jackardios\FileStash\Contracts\File;

class CacheFileRetrieved
{
    public function __construct(
        public readonly File $file,
        public readonly string $cachedPath,
        public readonly int $bytes,
        public readonly string $source
    ) {
    }
}
