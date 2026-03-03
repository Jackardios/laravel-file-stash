<?php

namespace Jackardios\FileStash\Events;

class CacheFileEvicted
{
    public function __construct(
        public readonly string $path,
        public readonly string $reason
    ) {
    }
}
