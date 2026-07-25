<?php

namespace Jackardios\FileStash\Events;

final readonly class CachePruneCompleted
{
    public function __construct(
        public int $deleted,
        public int $remaining,
        public int $totalSize,
        public bool $completed
    ) {}
}
