<?php

namespace Jackardios\FileStash\Exceptions;

use Exception;
use Throwable;

class FileIsTooLargeException extends Exception
{
    public readonly int $maxBytes;

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, int $maxBytes = 0)
    {
        parent::__construct($message, $code, $previous);
        $this->maxBytes = $maxBytes;
    }

    public static function create(int $maxBytes, int $code = 0, ?Throwable $previous = null): self
    {
        return new self("The file is too large with more than {$maxBytes} bytes.", $code, $previous, $maxBytes);
    }
}
