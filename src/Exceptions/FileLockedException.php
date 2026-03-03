<?php

namespace Jackardios\FileStash\Exceptions;

use Exception;
use Throwable;

class FileLockedException extends Exception
{
    public static function create(?string $message = null, int $code = 0, ?Throwable $previous = null): self
    {
        return new self($message ?? 'File is locked.', $code, $previous);
    }
}
