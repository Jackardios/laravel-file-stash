<?php

namespace Jackardios\FileStash\Events;

use Jackardios\FileStash\Contracts\File;

final readonly class CacheFileRetrieved
{
    public function __construct(
        public File $file,
        public string $cachedPath,
        public int $bytes,
        public string $source
    ) {}
}
