<?php

namespace Jackardios\FileStash\Exceptions;

use Exception;
use Throwable;

class MimeTypeIsNotAllowedException extends Exception
{
    public readonly string $mimeType;

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, string $mimeType = '')
    {
        parent::__construct($message, $code, $previous);
        $this->mimeType = $mimeType;
    }

    public static function create(string $mimeType, int $code = 0, ?Throwable $previous = null): self
    {
        return new self("MIME type '{$mimeType}' not allowed.", $code, $previous, $mimeType);
    }
}
