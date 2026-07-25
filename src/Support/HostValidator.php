<?php

namespace Jackardios\FileStash\Support;

use Jackardios\FileStash\Exceptions\HostNotAllowedException;

/**
 * Validates URL hosts against the allowed-hosts whitelist and (optionally)
 * blocks private, loopback and link-local addresses (SSRF protection).
 */
final class HostValidator
{
    /**
     * @param  array<int, string>|null  $allowedHosts  Null = all hosts allowed,
     *                                                 empty array = all hosts blocked. Entries may start with `*.` to
     *                                                 match subdomains (the root domain matches too).
     * @param  bool  $blockPrivateHosts  Reject hosts pointing at private or
     *                                   reserved addresses.
     */
    public function __construct(
        private readonly ?array $allowedHosts,
        private readonly bool $blockPrivateHosts = false,
    ) {}

    /**
     * Validate that a URL's host passes the configured restrictions.
     *
     * @throws HostNotAllowedException
     */
    public function validate(string $url): void
    {
        if ($this->allowedHosts === null && ! $this->blockPrivateHosts) {
            return;
        }

        $parts = parse_url($url);
        if (! isset($parts['host'])) {
            throw HostNotAllowedException::create('(empty)');
        }

        // Canonicalization strips IPv6 brackets and normalizes IPv6 literals,
        // so '[2001:DB8::0001]' matches an allowed-hosts entry '2001:db8::1'.
        $host = IpRanges::canonicalizeHost($parts['host']);

        if ($this->blockPrivateHosts && $this->isPrivateHost($host)) {
            throw HostNotAllowedException::create($host);
        }

        if ($this->allowedHosts === null) {
            return;
        }

        foreach ($this->allowedHosts as $allowedHost) {
            $allowedHost = IpRanges::canonicalizeHost(trim($allowedHost));

            if ($host === $allowedHost) {
                return;
            }

            if (str_starts_with($allowedHost, '*.')) {
                $domain = substr($allowedHost, 2);
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return;
                }
            }
        }

        throw HostNotAllowedException::create($host);
    }

    /**
     * Determine whether a host points at a private, loopback, link-local or
     * otherwise non-public address.
     *
     * Best effort: hostnames are resolved once and every returned address
     * (both A and AAAA records, plus /etc/hosts entries) is checked. A later
     * re-resolution by curl (DNS rebinding) cannot be detected here. Hosts
     * that resolve to nothing are treated as private because their target
     * cannot be proven public.
     */
    public function isPrivateHost(string $host): bool
    {
        // IPv6 literals arrive in brackets from parse_url.
        $candidate = trim($host, '[]');

        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return ! IpRanges::isPublic($candidate);
        }

        $ips = $this->resolveHost($candidate);
        if ($ips === []) {
            return true;
        }

        foreach ($ips as $ip) {
            if (! IpRanges::isPublic($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a hostname to every address curl might connect to.
     *
     * dns_get_record() covers both A and AAAA records (gethostbynamel is
     * IPv4-only — a private AAAA on a dual-stack host would bypass the check)
     * but skips /etc/hosts; gethostbynamel() covers /etc/hosts (common in
     * CI/docker setups). The union of both is checked.
     *
     * @return array<int, string>
     */
    private function resolveHost(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        $legacy = @gethostbynamel($host);
        if (is_array($legacy)) {
            $ips = array_merge($ips, $legacy);
        }

        return array_values(array_unique($ips));
    }
}
