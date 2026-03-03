<?php

namespace Jackardios\FileStash\Exceptions;

use Exception;
use Throwable;

class SourceResourceIsInvalidException extends Exception
{
    public static function create(?string $message = null, int $code = 0, ?Throwable $previous = null): self
    {
        return new self($message ?? 'The source resource is invalid.', $code, $previous);
    }
}
