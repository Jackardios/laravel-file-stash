<?php

namespace Jackardios\FileStash\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * Writable PSR-7 stream over a caller-owned file handle that stops accepting
 * data once more than $maxBytes have been written.
 *
 * Used as the Guzzle `sink` for remote downloads: when the limit is crossed,
 * write() returns 0, which makes curl abort the transfer immediately
 * (CURLE_WRITE_ERROR) instead of downloading the whole body. The caller
 * detects this via limitExceeded(). Non-curl handlers may ignore the short
 * write, so callers must check limitExceeded() after the transfer as well.
 *
 * The handle is borrowed, not owned: close() only lets go of it, so the
 * caller keeps the descriptor and the lock held through it (Windows locks
 * are mandatory — a second descriptor could not write to the locked file).
 * Construction truncates the handle, so a fresh instance per retry attempt
 * can never append to a partial body.
 */
final class SizeLimitedStream implements StreamInterface
{
    /**
     * @var resource|null
     */
    private $stream;

    private int $written = 0;

    private bool $limitExceeded = false;

    private bool $discardBody = false;

    /**
     * @param  resource  $stream  Writable, seekable handle; stays open after close().
     * @param  int  $maxBytes  Maximum number of bytes to accept, or -1 for unlimited.
     *
     * @throws RuntimeException When the handle cannot be truncated.
     */
    public function __construct($stream, private readonly int $maxBytes)
    {
        $this->stream = $stream;
        $this->reset();
    }

    /**
     * Whether a write beyond the configured limit was attempted.
     */
    public function limitExceeded(): bool
    {
        return $this->limitExceeded;
    }

    /**
     * Discard everything received so far and start a fresh response.
     *
     * Called from on_headers at the start of every response hop: Guzzle
     * reuses the same sink across redirect hops and rewinds it between them
     * WITHOUT truncating, so a redirect body longer than the final body
     * would otherwise leave trailing stale bytes in the published file, and
     * the byte counter would count redirect bodies against the size limit.
     *
     * With $discardBody the following body is not stored and not counted at
     * all — redirect-hop bodies are transport noise, not the payload.
     */
    public function reset(bool $discardBody = false): void
    {
        $stream = $this->ensureStream();

        if (! ftruncate($stream, 0) || fseek($stream, 0) === -1) {
            throw new RuntimeException('Could not truncate the download target.');
        }

        $this->written = 0;
        $this->limitExceeded = false;
        $this->discardBody = $discardBody;
    }

    public function write(string $string): int
    {
        $stream = $this->ensureStream();

        if ($this->discardBody) {
            // Pretend to consume the bytes so the transfer continues.
            return strlen($string);
        }

        if ($this->maxBytes >= 0 && $this->written + strlen($string) > $this->maxBytes) {
            $this->limitExceeded = true;

            // A short write makes curl abort the transfer (CURLE_WRITE_ERROR).
            return 0;
        }

        $written = fwrite($stream, $string);
        if ($written === false) {
            throw new RuntimeException('Could not write to the download target.');
        }

        $this->written += $written;

        return $written;
    }

    /**
     * Let go of the borrowed handle without closing it.
     */
    public function close(): void
    {
        $this->stream = null;
    }

    /**
     * @return resource|null
     */
    public function detach()
    {
        $stream = $this->stream;
        $this->stream = null;

        return $stream;
    }

    public function getSize(): ?int
    {
        if ($this->stream === null) {
            return null;
        }

        $stat = fstat($this->stream);
        if (! is_array($stat)) {
            return null;
        }

        return $stat['size'];
    }

    public function tell(): int
    {
        $position = ftell($this->ensureStream());
        if ($position === false) {
            throw new RuntimeException('Could not determine stream position.');
        }

        return $position;
    }

    public function eof(): bool
    {
        return $this->stream === null || feof($this->stream);
    }

    public function isSeekable(): bool
    {
        return $this->stream !== null;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (fseek($this->ensureStream(), $offset, $whence) === -1) {
            throw new RuntimeException('Could not seek in stream.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->stream !== null;
    }

    public function isReadable(): bool
    {
        return $this->stream !== null;
    }

    public function read(int $length): string
    {
        $data = fread($this->ensureStream(), max(1, $length));
        if ($data === false) {
            throw new RuntimeException('Could not read from stream.');
        }

        return $data;
    }

    public function getContents(): string
    {
        $contents = stream_get_contents($this->ensureStream());
        if ($contents === false) {
            throw new RuntimeException('Could not read stream contents.');
        }

        return $contents;
    }

    /**
     * @return mixed
     */
    public function getMetadata(?string $key = null)
    {
        if ($this->stream === null) {
            return $key === null ? [] : null;
        }

        $meta = stream_get_meta_data($this->stream);

        if ($key === null) {
            return $meta;
        }

        return $meta[$key] ?? null;
    }

    public function __toString(): string
    {
        try {
            if ($this->stream === null) {
                return '';
            }

            $this->rewind();

            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @return resource
     */
    private function ensureStream()
    {
        if ($this->stream === null) {
            throw new RuntimeException('Stream is detached.');
        }

        return $this->stream;
    }
}
