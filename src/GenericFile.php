<?php

namespace Jackardios\FileStash;

use InvalidArgumentException;
use Jackardios\FileStash\Contracts\File;

class GenericFile implements File
{
    /**
     * The file URL.
     */
    protected string $url;

    /**
     * Create a new instance.
     *
     * @param string $url The file URL (http://, https://, or diskname://)
     * @throws InvalidArgumentException If URL is empty or has invalid format
     */
    public function __construct(string $url)
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException('File URL cannot be empty');
        }

        if (!str_contains($url, '://')) {
            throw new InvalidArgumentException('File URL must contain a protocol (e.g., http://, https://, or diskname://)');
        }

        $this->url = $url;
    }

    /**
     * {@inheritdoc}
     */
    public function getUrl(): string
    {
        return $this->url;
    }
}
