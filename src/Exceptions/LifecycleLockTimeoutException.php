<?php

namespace Jackardios\FileStash\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The lifecycle or pin lock could not be acquired within
 * `lifecycle_lock_timeout`.
 *
 * Extends RuntimeException, which v4 threw for lock timeouts, so existing
 * catch blocks keep working.
 */
class LifecycleLockTimeoutException extends RuntimeException
{
    public static function create(?string $message = null, int $code = 0, ?Throwable $previous = null): self
    {
        return new self($message ?? 'Failed to acquire file cache lifecycle lock.', $code, $previous);
    }
}
