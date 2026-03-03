<?php

namespace Jackardios\FileStash\Exceptions;

use Exception;
use Throwable;

class InvalidConfigurationException extends Exception
{
    public readonly string $key;
    public readonly string $reason;

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, string $key = '', string $reason = '')
    {
        parent::__construct($message, $code, $previous);
        $this->key = $key;
        $this->reason = $reason;
    }

    public static function create(string $key, string $message, int $code = 0, ?Throwable $previous = null): self
    {
        return new self("Invalid configuration for '{$key}': {$message}", $code, $previous, $key, $message);
    }
}
