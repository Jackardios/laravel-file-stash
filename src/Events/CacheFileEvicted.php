<?php

namespace Jackardios\FileStash\Events;

final readonly class CacheFileEvicted
{
    public function __construct(
        public string $path,
        public string $reason
    ) {}
}
