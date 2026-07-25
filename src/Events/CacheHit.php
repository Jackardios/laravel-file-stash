<?php

namespace Jackardios\FileStash\Events;

use Jackardios\FileStash\Contracts\File;

final readonly class CacheHit
{
    public function __construct(
        public File $file,
        public string $cachedPath
    ) {}
}
