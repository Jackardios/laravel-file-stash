<?php

namespace Jackardios\FileStash\Exceptions;

use Exception;
use Throwable;

class SourceResourceTimedOutException extends Exception
{
    public static function create(?string $message = null, int $code = 0, ?Throwable $previous = null): self
    {
        return new self($message ?? 'The source stream timed out while reading data.', $code, $previous);
    }
}
