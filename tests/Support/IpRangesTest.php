<?php

namespace Jackardios\FileStash\Tests\Support;

use Jackardios\FileStash\Support\IpRanges;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpRangesTest extends TestCase
{
    #[DataProvider('blockedIpProvider')]
    public function testBlockedIps(string $ip): void
    {
        $this->assertFalse(IpRanges::isPublic($ip), "{$ip} must not be public");
    }

    public static function blockedIpProvider(): array
    {
        return [
            // first/last of each IPv4 range
            ['0.0.0.0'], ['0.255.255.255'],
            ['10.0.0.0'], ['10.255.255.255'],
            ['100.64.0.0'], ['100.127.255.255'], ['100.100.100.200'], // CGNAT + Alibaba metadata
            ['127.0.0.1'], ['127.255.255.255'],
            ['169.254.0.0'], ['169.254.169.254'], ['169.254.255.255'],
            ['172.16.0.0'], ['172.31.255.255'],
            ['192.0.0.0'], ['192.0.0.255'],
            ['192.0.2.0'], ['192.0.2.255'],
            ['192.88.99.0'], ['192.88.99.255'],
            ['192.168.0.0'], ['192.168.255.255'],
            ['198.18.0.0'], ['198.19.255.255'],
            ['198.51.100.0'], ['198.51.100.255'],
            ['203.0.113.0'], ['203.0.113.255'],
            ['224.0.0.0'], ['239.255.255.255'],
            ['240.0.0.0'], ['255.255.255.255'],
            // IPv6
            ['::'], ['::1'],
            ['64:ff9b::8.8.8.8'], ['64:ff9b::a9fe:a9fe'],       // NAT64 (routes to IPv4!)
            ['64:ff9b:1::1'],
            ['100::1'],
            ['2001::1'], ['2001:2::1'],                          // Teredo, benchmarking (2001::/23)
            ['2001:db8::1'],
            ['2002:808:808::1'],                                 // 6to4 blanket-denied
            ['3fff::1'],
            ['fc00::1'], ['fd00::1'],
            ['fe80::1'], ['febf::1'],
            ['fec0::1'],
            ['ff02::1'], ['ff00::1'],
            // outside global unicast 2000::/3
            ['::127.0.0.1'], ['::10.0.0.1'], ['::8.8.8.8'],      // IPv4-compatible ::/96
            ['::ffff:0:127.0.0.1'], ['::ffff:0:8.8.8.8'],        // SIIT IPv4-translated
            ['::2'], ['100:0:0:1::1'], ['5f00::1'],
            ['1fff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'], ['4000::1'],
            // v4-mapped v6 follows the IPv4 rules
            ['::ffff:127.0.0.1'], ['::ffff:10.0.0.1'], ['::ffff:100.64.0.1'], ['::ffff:169.254.169.254'],
            // garbage fails closed
            ['not-an-ip'], [''], ['999.1.1.1'],
        ];
    }

    #[DataProvider('publicIpProvider')]
    public function testPublicIps(string $ip): void
    {
        $this->assertTrue(IpRanges::isPublic($ip), "{$ip} must be public");
    }

    public static function publicIpProvider(): array
    {
        return [
            // neighbours just outside blocked ranges
            ['1.0.0.0'], ['9.255.255.255'], ['11.0.0.0'],
            ['100.63.255.255'], ['100.128.0.0'],
            ['126.255.255.255'], ['128.0.0.0'],
            ['169.253.255.255'], ['169.255.0.0'],
            ['172.15.255.255'], ['172.32.0.0'],
            ['192.0.1.0'], ['192.0.3.0'],
            ['192.88.98.255'], ['192.88.100.0'],
            ['192.167.255.255'], ['192.169.0.0'],
            ['198.17.255.255'], ['198.20.0.0'],
            ['198.51.99.255'], ['198.51.101.0'],
            ['203.0.112.255'], ['203.0.114.0'],
            ['223.255.255.255'],
            ['8.8.8.8'], ['142.250.74.36'],
            // IPv6 public
            ['2600::1'], ['2a00:1450:4001::1'], ['2003::1'],
            ['2000::'], ['2001:200::1'], ['3fff:1000::1'],       // edges of 2000::/3 and its deny list
            // v4-mapped PUBLIC v4 stays public
            ['::ffff:8.8.8.8'],
        ];
    }

    public function testCanonicalizeHost(): void
    {
        $this->assertSame('2001:db8::1', IpRanges::canonicalizeHost('[2001:DB8::0001]'));
        $this->assertSame('2001:db8::1', IpRanges::canonicalizeHost('2001:db8:0:0:0:0:0:1'));
        $this->assertSame('::1', IpRanges::canonicalizeHost('[::1]'));
        $this->assertSame('example.com', IpRanges::canonicalizeHost('EXAMPLE.com'));
        $this->assertSame('*.trusted.com', IpRanges::canonicalizeHost('*.Trusted.COM'));
        $this->assertSame('192.168.0.1', IpRanges::canonicalizeHost('192.168.0.1'));
        // malformed IPv6-looking input is returned lowercased, not mangled
        $this->assertSame('not:an:ip:literal', IpRanges::canonicalizeHost('NOT:an:IP:literal'));
    }
}
