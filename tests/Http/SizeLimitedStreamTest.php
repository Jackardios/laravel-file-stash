<?php

namespace Jackardios\FileStash\Tests\Http;

use Jackardios\FileStash\Http\SizeLimitedStream;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SizeLimitedStreamTest extends TestCase
{
    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/file_stash_stream_'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function testOpeningTruncatesPreviousContent(): void
    {
        // A retry after a failed attempt must never append to a partial body.
        file_put_contents($this->path, 'garbage from a previous attempt');

        $stream = new SizeLimitedStream($this->path, -1);
        $this->assertSame(0, $stream->getSize());

        $stream->write('fresh');
        $stream->close();

        $this->assertSame('fresh', file_get_contents($this->path));
    }

    public function testWriteWithinLimit(): void
    {
        $stream = new SizeLimitedStream($this->path, 10);

        $this->assertSame(5, $stream->write('12345'));
        $this->assertSame(5, $stream->write('67890'));
        $this->assertFalse($stream->limitExceeded());
        $stream->close();

        $this->assertSame('1234567890', file_get_contents($this->path));
    }

    public function testWriteBeyondLimitReturnsZeroAndSetsFlag(): void
    {
        $stream = new SizeLimitedStream($this->path, 5);

        $this->assertSame(5, $stream->write('12345'));
        // The short write is what makes curl abort the transfer.
        $this->assertSame(0, $stream->write('6'));
        $this->assertTrue($stream->limitExceeded());
        $stream->close();

        $this->assertSame('12345', file_get_contents($this->path));
    }

    public function testUnlimitedAcceptsEverything(): void
    {
        $stream = new SizeLimitedStream($this->path, -1);

        $data = str_repeat('x', 100_000);
        $this->assertSame(strlen($data), $stream->write($data));
        $this->assertFalse($stream->limitExceeded());
        $stream->close();
    }

    public function testResetTruncatesPreviouslyWrittenBytes(): void
    {
        $stream = new SizeLimitedStream($this->path, -1);
        $stream->write('redirect-hop body');

        $stream->reset();
        $stream->write('final');
        $stream->close();

        $this->assertSame('final', file_get_contents($this->path));
    }

    public function testResetClearsLimitStateAndRestartsCounting(): void
    {
        $stream = new SizeLimitedStream($this->path, 5);
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
        $stream = new SizeLimitedStream($this->path, 5);

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

    public function testResetAfterDetachThrows(): void
    {
        $stream = new SizeLimitedStream($this->path, -1);
        $resource = $stream->detach();

        try {
            $this->expectException(RuntimeException::class);
            $stream->reset();
        } finally {
            fclose($resource);
        }
    }

    public function testThrowsWhenPathIsNotWritable(): void
    {
        $this->expectException(RuntimeException::class);

        new SizeLimitedStream('/nonexistent-dir/'.bin2hex(random_bytes(8)).'/file', -1);
    }

    public function testWriteAfterDetachThrows(): void
    {
        $stream = new SizeLimitedStream($this->path, -1);
        $resource = $stream->detach();

        try {
            $this->expectException(RuntimeException::class);
            $stream->write('data');
        } finally {
            fclose($resource);
        }
    }
}
