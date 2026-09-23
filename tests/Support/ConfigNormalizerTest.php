<?php

namespace Jackardios\FileStash\Tests\Support;

use Jackardios\FileStash\Exceptions\InvalidConfigurationException;
use Jackardios\FileStash\Support\ConfigNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Extends the plain PHPUnit TestCase on purpose: ConfigNormalizer must work
 * without a Laravel application.
 */
class ConfigNormalizerTest extends TestCase
{
    private const PATH = '/tmp/file-stash-config-test';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function normalize(array $overrides = []): array
    {
        return ConfigNormalizer::normalize(array_merge(['path' => self::PATH], $overrides));
    }

    public function testDefaults(): void
    {
        $config = $this->normalize();

        $this->assertSame(-1, $config['max_file_size']);
        $this->assertSame(60, $config['max_age']);
        $this->assertSame(1_000_000_000, $config['max_size']);
        $this->assertSame(3, $config['lock_max_attempts']);
        $this->assertSame(-1.0, $config['lock_wait_timeout']);
        $this->assertSame(300.0, $config['timeout']);
        $this->assertSame(30.0, $config['connect_timeout']);
        $this->assertSame(30.0, $config['read_timeout']);
        $this->assertSame(300, $config['prune_timeout']);
        $this->assertSame([], $config['mime_types']);
        $this->assertNull($config['allowed_hosts']);
        $this->assertNull($config['allowed_disks']);
        $this->assertFalse($config['block_private_hosts']);
        $this->assertSame(0, $config['http_retries']);
        $this->assertSame(100, $config['http_retry_delay']);
        $this->assertSame(30.0, $config['lifecycle_lock_timeout']);
        $this->assertSame(100, $config['batch_chunk_size']);
        $this->assertSame(self::PATH, $config['path']);
        $this->assertSame(5, $config['max_redirects']);
        $this->assertSame(60, $config['touch_interval']);
        $this->assertFalse($config['events_enabled']);
    }

    // -------------------------------------------------------------------------
    // path
    // -------------------------------------------------------------------------

    public function testPathIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("'path'");

        ConfigNormalizer::normalize([]);
    }

    public function testMissingPathHintsAtPassingThePackageConfig(): void
    {
        // v4 filled in defaults for `new FileStash([], $client)`; v5 does not.
        $this->expectExceptionMessage("new FileStash(config('file-stash'), \$client)");

        ConfigNormalizer::normalize(['path' => null]);
    }

    #[DataProvider('invalidPathProvider')]
    public function testPathRejectsInvalidValues(mixed $path): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("'path'");

        ConfigNormalizer::normalize(['path' => $path]);
    }

    public static function invalidPathProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace' => ['   '],
            'relative path' => ['relative/cache/dir'],
            'relative path containing a drive' => ['cache/C:\\files'],
            'null' => [null],
            'integer' => [42],
            'array' => [['/tmp/cache']],
        ];
    }

    public function testPathTrailingSeparatorIsTrimmed(): void
    {
        $this->assertSame(self::PATH, $this->normalize(['path' => self::PATH.'/'])['path']);
    }

    public function testPathAcceptsWindowsStyleAbsolutePaths(): void
    {
        $this->assertSame('C:\\cache\\files', ConfigNormalizer::normalize(['path' => 'C:\\cache\\files'])['path']);
        $this->assertSame('\\\\server\\share', ConfigNormalizer::normalize(['path' => '\\\\server\\share'])['path']);
    }

    // -------------------------------------------------------------------------
    // numeric keys
    // -------------------------------------------------------------------------

    public function testNumericStringsAreAccepted(): void
    {
        $config = $this->normalize([
            'max_age' => '120',
            'max_size' => '1E+9',
            'lock_wait_timeout' => '2.5',
            'http_retries' => '3',
        ]);

        $this->assertSame(120, $config['max_age']);
        $this->assertSame(1_000_000_000, $config['max_size']);
        $this->assertSame(2.5, $config['lock_wait_timeout']);
        $this->assertSame(3, $config['http_retries']);
    }

    public function testIntegralFloatsAreAcceptedForIntKeys(): void
    {
        $this->assertSame(1_000_000_000, $this->normalize(['max_size' => 1E+9])['max_size']);
    }

    public function testIntegerStringsAreParsedExactly(): void
    {
        // Beyond 2^53 a detour through float would silently round.
        $this->assertSame(PHP_INT_MAX, $this->normalize(['max_size' => '9223372036854775807'])['max_size']);
        $this->assertSame(9007199254740993, $this->normalize(['max_size' => '9007199254740993'])['max_size']);
        $this->assertSame(9007199254740993, $this->normalize(['max_size' => '9.007199254740993e15'])['max_size']);
        $this->assertSame(1500, $this->normalize(['max_size' => '1.5E+3'])['max_size']);
        $this->assertSame(-1, $this->normalize(['max_file_size' => ' -1 '])['max_file_size']);
        // Zero fractions, leading zeros, and exponents that land on an integer.
        $this->assertSame(1, $this->normalize(['max_age' => '1.0'])['max_age']);
        $this->assertSame(25, $this->normalize(['max_age' => '2.50e1'])['max_age']);
        $this->assertSame(7, $this->normalize(['max_age' => '007'])['max_age']);
        $this->assertSame(1, $this->normalize(['max_age' => '1e0'])['max_age']);
        $this->assertSame(0, $this->normalize(['http_retries' => '.0'])['http_retries']);
    }

    #[DataProvider('invalidValueProvider')]
    public function testInvalidValuesAreRejected(string $key, mixed $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("'{$key}'");

        $this->normalize([$key => $value]);
    }

    public static function invalidValueProvider(): array
    {
        return [
            // non-numeric values for numeric keys
            'max_file_size string' => ['max_file_size', 'abc'],
            'max_age array' => ['max_age', []],
            'max_age fractional' => ['max_age', 1.5],
            'max_age bool' => ['max_age', true],
            'max_size null' => ['max_size', null],
            'max_size overflowing string' => ['max_size', '9223372036854775808'],
            'max_size float beyond exact range' => ['max_size', 1e19],
            'max_size float 2^63' => ['max_size', 9.2233720368547758E+18],
            'max_size fractional exponent string' => ['max_size', '12e-1'],
            'max_size huge exponent string' => ['max_size', '1e9999'],
            'max_size trailing garbage' => ['max_size', '12abc'],
            'max_size leading garbage' => ['max_size', 'x12'],
            'max_size exponent without digits' => ['max_size', '1e'],
            'max_size lone point' => ['max_size', '.'],
            'lock_wait_timeout NAN' => ['lock_wait_timeout', NAN],
            'timeout INF' => ['timeout', INF],
            'read_timeout overflowing string' => ['read_timeout', '1e999'],
            'lock_wait_timeout string' => ['lock_wait_timeout', 'abc'],
            'timeout bool' => ['timeout', false],
            'prune_timeout fractional string' => ['prune_timeout', '1.5'],
            'batch_chunk_size string' => ['batch_chunk_size', 'many'],
            // out-of-range numeric values
            'max_file_size below -1' => ['max_file_size', -2],
            'max_file_size zero' => ['max_file_size', 0],
            'max_age zero' => ['max_age', 0],
            'max_size negative' => ['max_size', -1],
            'lock_max_attempts zero' => ['lock_max_attempts', 0],
            'lock_wait_timeout below -1' => ['lock_wait_timeout', -2],
            // fractional values in (-1, 0) must not silently mean "unlimited"
            'lock_wait_timeout fractional negative' => ['lock_wait_timeout', -0.5],
            'timeout fractional negative' => ['timeout', -0.5],
            'connect_timeout fractional negative' => ['connect_timeout', -0.5],
            'read_timeout fractional negative' => ['read_timeout', -0.5],
            'read_timeout fractional negative string' => ['read_timeout', '-0.999'],
            'lifecycle_lock_timeout fractional negative' => ['lifecycle_lock_timeout', -0.5],
            'http_retries negative' => ['http_retries', -1],
            'http_retry_delay negative' => ['http_retry_delay', -100],
            'max_redirects negative' => ['max_redirects', -1],
            'touch_interval negative' => ['touch_interval', -1],
            'batch_chunk_size zero' => ['batch_chunk_size', 0],
            // mime_types: silently disabling the whitelist is not allowed
            'mime_types string' => ['mime_types', 'image/jpeg'],
            'mime_types null' => ['mime_types', null],
            'mime_types non-string entry' => ['mime_types', [123]],
            'mime_types empty entry' => ['mime_types', ['image/jpeg', '']],
            // user_agent
            'user_agent integer' => ['user_agent', 42],
            'user_agent empty' => ['user_agent', ''],
            'user_agent crlf injection' => ['user_agent', "Agent/1.0\r\nX-Injected: yes"],
            // booleans
            'events_enabled string' => ['events_enabled', 'not-a-bool'],
            'block_private_hosts string' => ['block_private_hosts', 'maybe'],
            // allowed_hosts
            'allowed_hosts integer' => ['allowed_hosts', 42],
            'allowed_hosts non-string entry' => ['allowed_hosts', [123]],
            // non-empty input parsing to zero hosts is a typo, not block-all
            'allowed_hosts separators only' => ['allowed_hosts', ','],
            'allowed_hosts spaced separators' => ['allowed_hosts', ' , '],
            'allowed_hosts blank entries array' => ['allowed_hosts', ['', ' ']],
            // allowed_disks
            'allowed_disks integer' => ['allowed_disks', 42],
            'allowed_disks non-string entry' => ['allowed_disks', [123]],
            'allowed_disks separators only' => ['allowed_disks', ','],
            'allowed_disks blank entries array' => ['allowed_disks', ['', ' ']],
        ];
    }

    // -------------------------------------------------------------------------
    // booleans
    // -------------------------------------------------------------------------

    public function testTimeoutSentinelAndNonNegativeValuesAccepted(): void
    {
        foreach (['lock_wait_timeout', 'timeout', 'connect_timeout', 'read_timeout', 'lifecycle_lock_timeout'] as $key) {
            $this->assertSame(-1.0, $this->normalize([$key => -1])[$key], $key);
            $this->assertSame(-1.0, $this->normalize([$key => '-1'])[$key], $key);
            $this->assertSame(0.0, $this->normalize([$key => 0])[$key], $key);
            $this->assertSame(1.5, $this->normalize([$key => 1.5])[$key], $key);
        }
    }

    public function testBooleanStringsAreAccepted(): void
    {
        $this->assertTrue($this->normalize(['events_enabled' => 'true'])['events_enabled']);
        $this->assertFalse($this->normalize(['events_enabled' => 'false'])['events_enabled']);
        $this->assertTrue($this->normalize(['block_private_hosts' => '1'])['block_private_hosts']);
    }

    // -------------------------------------------------------------------------
    // mime_types
    // -------------------------------------------------------------------------

    public function testMimeTypesAreTrimmed(): void
    {
        $config = $this->normalize(['mime_types' => [' image/jpeg ', 'image/png']]);

        $this->assertSame(['image/jpeg', 'image/png'], $config['mime_types']);
    }

    // -------------------------------------------------------------------------
    // allowed_hosts
    // -------------------------------------------------------------------------

    public function testAllowedHostsNullMeansNoRestriction(): void
    {
        $this->assertNull($this->normalize(['allowed_hosts' => null])['allowed_hosts']);
    }

    public function testAllowedHostsEmptyStringMeansNoRestriction(): void
    {
        $this->assertNull($this->normalize(['allowed_hosts' => ''])['allowed_hosts']);
    }

    public function testAllowedHostsEmptyArrayMeansDenyAll(): void
    {
        $this->assertSame([], $this->normalize(['allowed_hosts' => []])['allowed_hosts']);
    }

    public function testAllowedHostsCommaSeparatedString(): void
    {
        $config = $this->normalize(['allowed_hosts' => 'example.com, cdn.example.com,,*.trusted.com']);

        $this->assertSame(['example.com', 'cdn.example.com', '*.trusted.com'], $config['allowed_hosts']);
    }

    public function testAllowedHostsAreLowercased(): void
    {
        $config = $this->normalize(['allowed_hosts' => ['EXAMPLE.com', '*.Trusted.COM']]);

        $this->assertSame(['example.com', '*.trusted.com'], $config['allowed_hosts']);
    }

    // -------------------------------------------------------------------------
    // allowed_disks
    // -------------------------------------------------------------------------

    public function testAllowedDisksNullOrEmptyStringMeansNoRestriction(): void
    {
        $this->assertNull($this->normalize(['allowed_disks' => null])['allowed_disks']);
        $this->assertNull($this->normalize(['allowed_disks' => ''])['allowed_disks']);
    }

    public function testAllowedDisksEmptyArrayMeansNoDisks(): void
    {
        $this->assertSame([], $this->normalize(['allowed_disks' => []])['allowed_disks']);
    }

    public function testAllowedDisksCommaSeparatedString(): void
    {
        $config = $this->normalize(['allowed_disks' => ' s3, public,,']);

        $this->assertSame(['s3', 'public'], $config['allowed_disks']);
    }
}
