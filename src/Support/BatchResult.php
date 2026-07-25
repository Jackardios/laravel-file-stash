<?php

namespace Jackardios\FileStash\Support;

use Throwable;

/**
 * Outcome of a batch retrieval: the callback result, the cache paths that
 * were successfully retrieved before any failure, and the failure itself (if
 * any). Carrying the exception instead of throwing lets batchOnce() clean up
 * the already-retrieved entries before rethrowing.
 */
final readonly class BatchResult
{
    /**
     * @param  array<int, string>  $paths
     */
    public function __construct(
        public mixed $result,
        public array $paths,
        public ?Throwable $exception = null,
    ) {}
}
