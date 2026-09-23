<?php

namespace Jackardios\FileStash\Exceptions;

use Throwable;

/**
 * A `disk://path` URL names a storage disk that is not in `allowed_disks`.
 *
 * Extends HostNotAllowedException, so existing handlers for rejected
 * sources catch it too; `$host` holds the disk name as well.
 */
class DiskNotAllowedException extends HostNotAllowedException
{
    public readonly string $disk;

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, string $disk = '')
    {
        parent::__construct($message, $code, $previous, $disk);
        $this->disk = $disk;
    }

    public static function create(string $host, int $code = 0, ?Throwable $previous = null): self
    {
        return new self("Storage disk '{$host}' is not in the allowed disks list.", $code, $previous, $host);
    }
}
