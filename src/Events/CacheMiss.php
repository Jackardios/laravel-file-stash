<?php

namespace Jackardios\FileStash\Events;

use Jackardios\FileStash\Contracts\File;

final readonly class CacheMiss
{
    public function __construct(
        public File $file
    ) {}
}
