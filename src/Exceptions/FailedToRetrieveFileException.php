<?php

namespace Jackardios\FileStash\Exceptions;

use Exception;
use Throwable;

class FailedToRetrieveFileException extends Exception
{
    /**
     * @param  int  $statusCode  HTTP status code that caused the failure, or 0 when not applicable.
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        public readonly int $statusCode = 0
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function create(?string $message = null, int $code = 0, ?Throwable $previous = null, int $statusCode = 0): self
    {
        return new self($message ?? 'Failed to retrieve file.', $code, $previous, $statusCode);
    }
}
