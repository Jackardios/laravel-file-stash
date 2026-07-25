<?php

namespace Jackardios\FileStash\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The lifecycle lock could not be acquired within `lifecycle_lock_timeout`.
 *
 * Extends RuntimeException for backwards compatibility with v5.0 code that
 * caught the generic lock timeout.
 */
class LifecycleLockTimeoutException extends RuntimeException
{
    public static function create(?string $message = null, int $code = 0, ?Throwable $previous = null): self
    {
        return new self($message ?? 'Failed to acquire file cache lifecycle lock.', $code, $previous);
    }
}
