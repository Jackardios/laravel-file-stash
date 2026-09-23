<?php

namespace Jackardios\FileStash\Tests\Support;

use Jackardios\FileStash\Support\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlTest extends TestCase
{
    #[DataProvider('provideUrlsForEncoding')]
    public function testEncode(string $inputUrl, string $expectedUrl): void
    {
        $this->assertSame($expectedUrl, Url::encode($inputUrl));
    }

    public static function provideUrlsForEncoding(): array
    {
        return [
            'no encoding needed' => ['http://example.com/path/file.jpg', 'http://example.com/path/file.jpg'],
            'space encoding' => ['http://example.com/path with space/file name.jpg', 'http://example.com/path%20with%20space/file%20name.jpg'],
            'plus sign not encoded' => ['http://example.com/path+plus/file+name.jpg', 'http://example.com/path+plus/file+name.jpg'],
            'mixed chars' => ['http://example.com/path with space/and+plus.jpg', 'http://example.com/path%20with%20space/and+plus.jpg'],
            'query string spaces encoded' => ['http://example.com/pa th?q=a+b c', 'http://example.com/pa%20th?q=a+b%20c'],
            'user only' => ['http://admin@example.com/path', 'http://admin@example.com/path'],
            'user and pass' => ['http://admin:secret@example.com/path', 'http://admin:secret@example.com/path'],
            'user with spaces' => ['http://my user@example.com/path', 'http://my%20user@example.com/path'],
            'user pass and port' => ['http://admin:se cret@example.com:8080/path', 'http://admin:se%20cret@example.com:8080/path'],
            'brackets in path' => ['http://example.com/path[with]/brackets.jpg', 'http://example.com/path%5Bwith%5D/brackets.jpg'],
            'ipv6 host brackets preserved' => ['http://[::1]/path[with]/brackets.jpg', 'http://[::1]/path%5Bwith%5D/brackets.jpg'],
        ];
    }

    public function testIsRemote(): void
    {
        $this->assertTrue(Url::isRemote('https://example.com/file.jpg'));
        $this->assertTrue(Url::isRemote('http://example.com/file.jpg'));
        $this->assertTrue(Url::isRemote('HTTPS://example.com/file.jpg'));
        $this->assertFalse(Url::isRemote('s3://bucket/file.jpg'));
        $this->assertFalse(Url::isRemote('fixtures://file.jpg'));
        $this->assertFalse(Url::isRemote('/local/path/file.jpg'));
        $this->assertFalse(Url::isRemote('not-a-url'));
    }

    public function testSplitByProtocol(): void
    {
        $this->assertSame(['s3', 'bucket/file.jpg'], Url::splitByProtocol('s3://bucket/file.jpg'));
        $this->assertSame(['https', 'example.com/a'], Url::splitByProtocol('https://example.com/a'));
        $this->assertSame(['no-protocol'], Url::splitByProtocol('no-protocol'));
    }

    public function testEncodeWithCyrillic(): void
    {
        $result = Url::encode('https://example.com/файл.jpg');

        $this->assertStringContainsString('example.com/', $result);
        // Cyrillic characters should be percent-encoded
        $this->assertStringNotContainsString('файл', $result);
        $this->assertStringContainsString('.jpg', $result);
    }

    public function testEncodePreservesAlreadyEncodedSequences(): void
    {
        // Already-encoded %20 should be preserved
        $result = Url::encode('https://example.com/path%20with%20spaces/file.jpg');

        $this->assertStringContainsString('%20', $result);
    }

    public function testEncodeWithCjkCharacters(): void
    {
        $result = Url::encode('https://example.com/图片.jpg');

        $this->assertStringNotContainsString('图片', $result);
        $this->assertStringContainsString('.jpg', $result);
    }

    public function testEncodeSpaces(): void
    {
        $this->assertSame(
            'https://example.com/path%20with%20spaces.jpg?q=a%20b',
            Url::encode('https://example.com/path with spaces.jpg?q=a b')
        );
    }

    public function testSanitizeForLogging(): void
    {
        // URL without credentials - unchanged
        $this->assertSame(
            'https://example.com/path',
            Url::sanitizeForLogging('https://example.com/path')
        );

        // URL with user only
        $this->assertSame(
            'https://***@example.com/path',
            Url::sanitizeForLogging('https://user@example.com/path')
        );

        // URL with user and password
        $this->assertSame(
            'https://***:***@example.com/path',
            Url::sanitizeForLogging('https://user:pass@example.com/path')
        );

        // Invalid URL - returned as-is
        $this->assertSame(
            'not-a-url',
            Url::sanitizeForLogging('not-a-url')
        );

        // URL with port
        $this->assertSame(
            'https://***:***@example.com:8080/path',
            Url::sanitizeForLogging('https://user:pass@example.com:8080/path')
        );
    }

    public function testSanitizeForLoggingRedactsQueryValuesAndDropsFragment(): void
    {
        // Parameter names stay readable for debugging; values (signatures,
        // tokens) and bare parameters do not.
        $this->assertSame(
            'https://example.com/path?X-Amz-Signature=***&token=***&***&empty=***',
            Url::sanitizeForLogging('https://example.com/path?X-Amz-Signature=abc&token=s3cr3t&bare&empty=#frag')
        );
        $this->assertSame('https://example.com/path', Url::sanitizeForLogging('https://example.com/path#token=abc'));
    }

    public function testRedactUrlsReplacesEveryUrlInText(): void
    {
        $this->assertSame(
            'Server error: `GET https://***:***@a.test/x?sig=***` resulted in a `500` response; see http://b.test/y?id=***.',
            Url::redactUrls('Server error: `GET https://u:p@a.test/x?sig=abc` resulted in a `500` response; see http://b.test/y?id=1.')
        );
        $this->assertSame('no urls here', Url::redactUrls('no urls here'));
    }
}
