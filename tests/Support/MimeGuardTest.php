<?php

namespace Jackardios\FileStash\Tests\Support;

use Jackardios\FileStash\Exceptions\MimeTypeIsNotAllowedException;
use Jackardios\FileStash\Support\MimeGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MimeGuardTest extends TestCase
{
    #[DataProvider('normalizeProvider')]
    public function testNormalize(mixed $input, string $expected): void
    {
        $this->assertSame($expected, MimeGuard::normalize($input));
    }

    public static function normalizeProvider(): array
    {
        return [
            'bare type' => ['image/jpeg', 'image/jpeg'],
            'uppercase' => ['Image/JPEG', 'image/jpeg'],
            'charset parameter' => ['text/html; charset=UTF-8', 'text/html'],
            'padded' => ['  image/png  ', 'image/png'],
            'parameter only' => ['; charset=utf-8', '(unknown)'],
            'empty string' => ['', '(unknown)'],
            'null' => [null, '(unknown)'],
            'not a string' => [42, '(unknown)'],
        ];
    }

    public function testEnsureAllowedIsNoOpWithoutWhitelist(): void
    {
        MimeGuard::ensureAllowed('application/x-evil', []);
        MimeGuard::ensureAllowed(null, []);
        $this->addToAssertionCount(2);
    }

    public function testEnsureAllowedAcceptsNormalizedVariants(): void
    {
        MimeGuard::ensureAllowed('image/jpeg', ['image/jpeg']);
        MimeGuard::ensureAllowed('Image/JPEG; charset=binary', ['image/jpeg']);
        $this->addToAssertionCount(2);
    }

    public function testEnsureAllowedRejectsDisallowedType(): void
    {
        $this->expectException(MimeTypeIsNotAllowedException::class);
        $this->expectExceptionMessage('text/html');

        MimeGuard::ensureAllowed('Text/HTML; charset=UTF-8', ['image/jpeg']);
    }

    public function testEnsureAllowedRejectsUndetectableType(): void
    {
        // Deny by default: an unknown type can never match a whitelist.
        $this->expectException(MimeTypeIsNotAllowedException::class);
        $this->expectExceptionMessage('(unknown)');

        MimeGuard::ensureAllowed(null, ['image/jpeg']);
    }
}
