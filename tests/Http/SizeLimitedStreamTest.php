<?php

namespace Jackardios\FileStash\Tests\Http;

use Jackardios\FileStash\Http\SizeLimitedStream;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SizeLimitedStreamTest extends TestCase
{
    protected string $path;

    /**
     * @var resource
     */
    protected $handle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/file_stash_stream_'.bin2hex(random_bytes(8));
        $this->handle = fopen($this->path, 'x+b');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        @unlink($this->path);
        parent::tearDown();
    }

    public function testConstructionTruncatesTheHandle(): void
    {
        // A retry after a failed attempt must never append to a partial body.
        fwrite($this->handle, 'garbage from a previous attempt');

        $stream = new SizeLimitedStream($this->handle, -1);
        $this->assertSame(0, $stream->getSize());

        $stream->write('fresh');
        $stream->close();

        $this->assertSame('fresh', file_get_contents($this->path));
    }

    public function testCloseLeavesTheHandleOpen(): void
    {
        // The caller owns the handle (and the lock held through it); Guzzle
        // closing the response body must not release either.
        $this->assertTrue(flock($this->handle, LOCK_EX));

        $stream = new SizeLimitedStream($this->handle, -1);
        $stream->write('data');
        $stream->close();
        unset($stream);

        $this->assertIsResource($this->handle);
        $foreign = fopen($this->path, 'rb');
        $this->assertFalse(flock($foreign, LOCK_SH | LOCK_NB), 'The lock must still be held.');
        fclose($foreign);
    }

    public function testThrowsWhenTheHandleCannotBeTruncated(): void
    {
        $readOnly = fopen($this->path, 'rb');

        try {
            $this->expectException(RuntimeException::class);
            new SizeLimitedStream($readOnly, -1);
        } finally {
            fclose($readOnly);
        }
    }

    public function testWriteWithinLimit(): void
    {
        $stream = new SizeLimitedStream($this->handle, 10);

        $this->assertSame(5, $stream->write('12345'));
        $this->assertSame(5, $stream->write('67890'));
        $this->assertFalse($stream->limitExceeded());
        $stream->close();

        $this->assertSame('1234567890', file_get_contents($this->path));
    }

    public function testWriteBeyondLimitReturnsZeroAndSetsFlag(): void
    {
        $stream = new SizeLimitedStream($this->handle, 5);

        $this->assertSame(5, $stream->write('12345'));
        // The short write is what makes curl abort the transfer.
        $this->assertSame(0, $stream->write('6'));
        $this->assertTrue($stream->limitExceeded());
        $stream->close();

        $this->assertSame('12345', file_get_contents($this->path));
    }

    public function testUnlimitedAcceptsEverything(): void
    {
        $stream = new SizeLimitedStream($this->handle, -1);

        $data = str_repeat('x', 100_000);
        $this->assertSame(strlen($data), $stream->write($data));
        $this->assertFalse($stream->limitExceeded());
        $stream->close();
    }

    public function testResetTruncatesPreviouslyWrittenBytes(): void
    {
        $stream = new SizeLimitedStream($this->handle, -1);
        $stream->write('redirect-hop body');

        $stream->reset();
        $stream->write('final');
        $stream->close();

        $this->assertSame('final', file_get_contents($this->path));
    }

    public function testResetClearsLimitStateAndRestartsCounting(): void
    {
        $stream = new SizeLimitedStream($this->handle, 5);
        $stream->write('12345');
        $this->assertSame(0, $stream->write('6'));
        $this->assertTrue($stream->limitExceeded());

        $stream->reset();

        $this->assertFalse($stream->limitExceeded());
        $this->assertSame(5, $stream->write('abcde'));
        $stream->close();

        $this->assertSame('abcde', file_get_contents($this->path));
    }

    public function testDiscardModeSwallowsWritesUntilNextReset(): void
    {
        $stream = new SizeLimitedStream($this->handle, 5);

        $stream->reset(discardBody: true);
        // Larger than the limit: discarded bytes are neither stored nor counted.
        $this->assertSame(10, $stream->write('0123456789'));
        $this->assertFalse($stream->limitExceeded());
        $this->assertSame(0, $stream->getSize());

        $stream->reset();
        $this->assertSame(5, $stream->write('final'));
        $stream->close();

        $this->assertSame('final', file_get_contents($this->path));
    }

    public function testResetAfterCloseThrows(): void
    {
        $stream = new SizeLimitedStream($this->handle, -1);
        $stream->close();

        $this->expectException(RuntimeException::class);
        $stream->reset();
    }

    public function testWriteAfterDetachThrows(): void
    {
        $stream = new SizeLimitedStream($this->handle, -1);
        $this->assertSame($this->handle, $stream->detach());

        $this->expectException(RuntimeException::class);
        $stream->write('data');
    }
}
