<?php

namespace Jackardios\FileStash\Tests\Support;

use Jackardios\FileStash\Support\HostValidator;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * DNS resolution behavior of HostValidator::isPrivateHost via php-mock.
 * Mocks live in the Jackardios\FileStash\Support namespace (php-mock is
 * namespace-scoped).
 */
#[RunTestsInSeparateProcesses]
class HostValidatorDnsMockTest extends TestCase
{
    use PHPMock;

    private const NS = 'Jackardios\\FileStash\\Support';

    public function testUnresolvableHostIsBlocked()
    {
        // Both sources empty => cannot prove the target public => fail closed.
        $this->getFunctionMock(self::NS, 'dns_get_record')
            ->expects($this->once())->willReturn([]);
        $this->getFunctionMock(self::NS, 'gethostbynamel')
            ->expects($this->once())->willReturn(false);

        $validator = new HostValidator(null, true);
        $this->assertTrue($validator->isPrivateHost('unresolvable.example'));
    }

    public function testHostnameResolvingToLoopbackIsBlocked()
    {
        $this->getFunctionMock(self::NS, 'dns_get_record')
            ->expects($this->once())->willReturn([]);
        $this->getFunctionMock(self::NS, 'gethostbynamel')
            ->expects($this->once())->willReturn(['127.0.0.1']);

        $validator = new HostValidator(null, true);
        $this->assertTrue($validator->isPrivateHost('localhost'));
    }

    public function testEtcHostsOnlyPublicHostIsAllowed()
    {
        // dns_get_record skips /etc/hosts (docker/CI); gethostbynamel covers it.
        $this->getFunctionMock(self::NS, 'dns_get_record')
            ->expects($this->once())->willReturn([]);
        $this->getFunctionMock(self::NS, 'gethostbynamel')
            ->expects($this->once())->willReturn(['93.184.216.34']);

        $validator = new HostValidator(null, true);
        $this->assertFalse($validator->isPrivateHost('hosts-file.example'));
    }

    public function testPrivateAaaaRecordBlocksDualStackHost()
    {
        // Public A record, private AAAA: curl may connect over IPv6, so the
        // AAAA must be checked too.
        $this->getFunctionMock(self::NS, 'dns_get_record')
            ->expects($this->once())
            ->with('dual-stack.example', DNS_A | DNS_AAAA)
            ->willReturn([
                ['type' => 'A', 'ip' => '93.184.216.34'],
                ['type' => 'AAAA', 'ipv6' => '::1'],
            ]);
        $this->getFunctionMock(self::NS, 'gethostbynamel')
            ->expects($this->once())->willReturn(['93.184.216.34']);

        $validator = new HostValidator(null, true);
        $this->assertTrue($validator->isPrivateHost('dual-stack.example'));
    }

    public function testAaaaOnlyPublicHostIsAllowed()
    {
        // gethostbynamel is IPv4-only and returns false for AAAA-only hosts;
        // dns_get_record must rescue them.
        $this->getFunctionMock(self::NS, 'dns_get_record')
            ->expects($this->once())->willReturn([
                ['type' => 'AAAA', 'ipv6' => '2600::1'],
            ]);
        $this->getFunctionMock(self::NS, 'gethostbynamel')
            ->expects($this->once())->willReturn(false);

        $validator = new HostValidator(null, true);
        $this->assertFalse($validator->isPrivateHost('ipv6-only.example'));
    }

    public function testPrivateARecordFromDnsBlocksHostDespitePublicHostsEntry()
    {
        // The two sources are unioned: a private A record from DNS counts even
        // when gethostbynamel reports only public addresses.
        $this->getFunctionMock(self::NS, 'dns_get_record')
            ->expects($this->once())->willReturn([
                ['type' => 'A', 'ip' => '10.0.0.1'],
            ]);
        $this->getFunctionMock(self::NS, 'gethostbynamel')
            ->expects($this->once())->willReturn(['93.184.216.34']);

        $validator = new HostValidator(null, true);
        $this->assertTrue($validator->isPrivateHost('split.example'));
    }

    public function testIpLiteralsAreClassifiedWithoutDns()
    {
        $this->getFunctionMock(self::NS, 'dns_get_record')->expects($this->never());
        $this->getFunctionMock(self::NS, 'gethostbynamel')->expects($this->never());

        $validator = new HostValidator(null, true);
        $this->assertFalse($validator->isPrivateHost('[2600::1]'));
        $this->assertFalse($validator->isPrivateHost('93.184.216.34'));
        $this->assertTrue($validator->isPrivateHost('[::1]'));
    }

    public function testDnsQueryFailureFallsBackToGethostbynamel()
    {
        $this->getFunctionMock(self::NS, 'dns_get_record')
            ->expects($this->once())->willReturn(false);
        $this->getFunctionMock(self::NS, 'gethostbynamel')
            ->expects($this->once())->willReturn(['10.0.0.5']);

        $validator = new HostValidator(null, true);
        $this->assertTrue($validator->isPrivateHost('private-a.example'));
    }
}
