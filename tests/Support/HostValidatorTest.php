<?php

namespace Jackardios\FileStash\Tests\Support;

use Jackardios\FileStash\Exceptions\HostNotAllowedException;
use Jackardios\FileStash\Support\HostValidator;
use PHPUnit\Framework\TestCase;

class HostValidatorTest extends TestCase
{
    public function testAllowedHostsValidation(): void
    {
        $validator = new HostValidator(['example.com', 'cdn.example.com']);

        // Should not throw for allowed hosts
        $validator->validate('https://example.com/image.jpg');
        $validator->validate('https://cdn.example.com/image.jpg');
        $this->addToAssertionCount(2);

        // Should throw for disallowed host
        $this->expectException(HostNotAllowedException::class);
        $validator->validate('https://evil.com/image.jpg');
    }

    public function testAllowedHostsWildcard(): void
    {
        $validator = new HostValidator(['*.example.com']);

        // Should allow subdomains
        $validator->validate('https://cdn.example.com/image.jpg');
        $validator->validate('https://images.cdn.example.com/image.jpg');

        // Should also allow the root domain
        $validator->validate('https://example.com/image.jpg');
        $this->addToAssertionCount(3);

        // Should throw for different domain
        $this->expectException(HostNotAllowedException::class);
        $validator->validate('https://notexample.com/image.jpg');
    }

    public function testNullAllowsAllHosts(): void
    {
        $validator = new HostValidator(null);

        $validator->validate('https://any-host.example.com/image.jpg');
        $validator->validate('https://another.org/file');
        $this->addToAssertionCount(2);
    }

    public function testEmptyArrayBlocksAllHosts(): void
    {
        $validator = new HostValidator([]);

        $this->expectException(HostNotAllowedException::class);
        $validator->validate('https://example.com/image.jpg');
    }

    public function testUrlWithoutHostIsRejected(): void
    {
        $validator = new HostValidator(['example.com']);

        $this->expectException(HostNotAllowedException::class);
        $this->expectExceptionMessage('(empty)');
        $validator->validate('/no-host-url');
    }

    public function testHostMatchingIsCaseInsensitive(): void
    {
        $validator = new HostValidator(['Example.COM']);

        $validator->validate('https://EXAMPLE.com/image.jpg');
        $this->addToAssertionCount(1);
    }

    public function testBlockPrivateHostsRejectsPrivateAddresses(): void
    {
        $validator = new HostValidator(null, true);

        // Public IP literal passes without DNS resolution
        $validator->validate('https://93.184.216.34/file.jpg');
        $this->addToAssertionCount(1);

        $privateHosts = [
            '127.0.0.1',
            '10.0.0.1',
            '172.16.5.5',
            '192.168.1.1',
            '169.254.1.1',
            '[::1]',
            '[fd00::1]',
            'localhost', // resolves to a loopback address
            // ranges missed by PHP's filter_var reserved-range flags
            '100.64.0.1',        // CGNAT
            '100.100.100.200',   // Alibaba Cloud metadata
            '198.18.0.1',        // benchmarking
            '192.0.0.170',       // RFC 6890
            '198.51.100.7',      // TEST-NET-2
            '203.0.113.9',       // TEST-NET-3
            '224.0.0.1',         // multicast
            '240.0.0.1',         // reserved
            '[::ffff:127.0.0.1]', // v4-mapped loopback
            '[64:ff9b::a9fe:a9fe]', // NAT64 route to 169.254.169.254
            '[2002:7f00:1::]',   // 6to4 embedding 127.0.0.1
            '[fe80::1]',
            '[ff02::1]',
        ];

        foreach ($privateHosts as $host) {
            try {
                $validator->validate("https://{$host}/file.jpg");
                $this->fail("Expected HostNotAllowedException for {$host}");
            } catch (HostNotAllowedException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testIpv6LiteralMatchesAllowedHostsInNaturalForm(): void
    {
        // Brackets from parse_url and zero-compression differences must not
        // prevent a whitelisted IPv6 literal from matching.
        $validator = new HostValidator(['2001:db8::1']);

        $validator->validate('https://[2001:db8::1]/file.jpg');
        $validator->validate('https://[2001:DB8::0001]/file.jpg');
        $this->addToAssertionCount(2);

        $this->expectException(HostNotAllowedException::class);
        $validator->validate('https://[2001:db8::2]/file.jpg');
    }

    public function testBlockPrivateHostsDisabledAllowsPrivateAddresses(): void
    {
        $validator = new HostValidator(null, false);

        // No exception with the (default) protection disabled
        $validator->validate('https://127.0.0.1/file.jpg');
        $validator->validate('https://192.168.1.1/file.jpg');
        $this->addToAssertionCount(2);
    }

    public function testBlockPrivateHostsCombinesWithAllowedHosts(): void
    {
        $validator = new HostValidator(['192.168.1.1'], true);

        // Whitelisted but private: private check wins
        $this->expectException(HostNotAllowedException::class);
        $validator->validate('https://192.168.1.1/file.jpg');
    }
}
