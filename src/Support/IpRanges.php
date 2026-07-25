<?php

namespace Jackardios\FileStash\Support;

/**
 * Explicit reserved-range classification for SSRF protection.
 *
 * PHP's FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE misses several ranges that
 * matter for SSRF (CGNAT 100.64.0.0/10 hosting cloud metadata services,
 * benchmarking 198.18.0.0/15, NAT64 64:ff9b::/96, multicast, ...), so the
 * deny list is spelled out here in full.
 */
final class IpRanges
{
    /**
     * @var array<int, string>
     */
    private const IPV4_BLOCKED = [
        '0.0.0.0/8',            // "this network"
        '10.0.0.0/8',           // RFC 1918 private
        '100.64.0.0/10',        // RFC 6598 CGNAT (incl. cloud metadata services)
        '127.0.0.0/8',          // loopback
        '169.254.0.0/16',       // link-local (incl. 169.254.169.254 metadata)
        '172.16.0.0/12',        // RFC 1918 private
        '192.0.0.0/24',         // RFC 6890 protocol assignments
        '192.0.2.0/24',         // TEST-NET-1
        '192.88.99.0/24',       // deprecated 6to4 relay anycast
        '192.168.0.0/16',       // RFC 1918 private
        '198.18.0.0/15',        // RFC 2544 benchmarking
        '198.51.100.0/24',      // TEST-NET-2
        '203.0.113.0/24',       // TEST-NET-3
        '224.0.0.0/4',          // multicast
        '240.0.0.0/4',          // reserved (incl. broadcast)
    ];

    /**
     * @var array<int, string>
     */
    private const IPV6_BLOCKED = [
        '::/128',               // unspecified
        '::1/128',              // loopback
        '64:ff9b::/96',         // NAT64 well-known prefix (routes to IPv4!)
        '64:ff9b:1::/48',       // NAT64 local-use prefix
        '100::/64',             // discard-only
        '2001::/23',            // IETF protocol assignments (incl. Teredo, benchmarking)
        '2001:db8::/32',        // documentation
        '2002::/16',            // 6to4 (deprecated; embeds arbitrary IPv4 — blanket-denied)
        '3fff::/20',            // documentation
        'fc00::/7',             // unique local addresses
        'fe80::/10',            // link-local
        'fec0::/10',            // deprecated site-local
        'ff00::/8',             // multicast
    ];

    /**
     * Parsed deny lists, keyed by address length in bytes.
     *
     * @var array<int, array<int, array{prefix: string, bits: int}>>|null
     */
    private static ?array $parsed = null;

    /**
     * Whether the IP address (v4 or v6 textual form) is publicly routable —
     * i.e. outside every private, loopback, link-local and reserved range.
     * Unparseable input is not public (fail closed).
     */
    public static function isPublic(string $ip): bool
    {
        $bytes = @inet_pton($ip);
        if ($bytes === false) {
            return false;
        }

        // IPv4-mapped IPv6 (::ffff:a.b.c.d) connects to the embedded IPv4
        // address — classify by the IPv4 rules.
        if (strlen($bytes) === 16 && substr($bytes, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            $bytes = substr($bytes, 12);
        }

        foreach (self::parsedRanges()[strlen($bytes)] ?? [] as $range) {
            if (self::inRange($bytes, $range['prefix'], $range['bits'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize a URL host for comparison: strip IPv6 brackets, lowercase,
     * and canonicalize IPv6 literals ('2001:DB8::0001' and '2001:db8::1'
     * become identical). Non-IP hosts are returned lowercased unchanged.
     */
    public static function canonicalizeHost(string $host): string
    {
        $host = strtolower(trim($host, '[]'));

        if (str_contains($host, ':')) {
            $bytes = @inet_pton($host);
            if ($bytes !== false) {
                $canonical = inet_ntop($bytes);
                if (is_string($canonical)) {
                    return $canonical;
                }
            }
        }

        return $host;
    }

    /**
     * @return array<int, array<int, array{prefix: string, bits: int}>>
     */
    private static function parsedRanges(): array
    {
        if (self::$parsed !== null) {
            return self::$parsed;
        }

        $parsed = [4 => [], 16 => []];
        foreach ([self::IPV4_BLOCKED, self::IPV6_BLOCKED] as $list) {
            foreach ($list as $cidr) {
                [$address, $bits] = explode('/', $cidr);
                $prefix = inet_pton($address);
                assert($prefix !== false);
                $parsed[strlen($prefix)][] = ['prefix' => $prefix, 'bits' => (int) $bits];
            }
        }

        return self::$parsed = $parsed;
    }

    private static function inRange(string $bytes, string $prefix, int $bits): bool
    {
        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && substr($bytes, 0, $wholeBytes) !== substr($prefix, 0, $wholeBytes)) {
            return false;
        }

        $remainderBits = $bits % 8;
        if ($remainderBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainderBits) & 0xFF;

        return (ord($bytes[$wholeBytes]) & $mask) === (ord($prefix[$wholeBytes]) & $mask);
    }
}
