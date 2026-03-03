<?php

namespace Jackardios\FileStash\Exceptions;

use Exception;
use Throwable;

class HostNotAllowedException extends Exception
{
    public readonly string $host;

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, string $host = '')
    {
        parent::__construct($message, $code, $previous);
        $this->host = $host;
    }

    public static function create(string $host, int $code = 0, ?Throwable $previous = null): self
    {
        return new self("Host '{$host}' is not in the allowed hosts list.", $code, $previous, $host);
    }
}
