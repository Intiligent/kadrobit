<?php

namespace Tests\Unit\Marketplaces;

use App\Support\HostResolver;
use PHPUnit\Framework\TestCase;

class HostResolverTest extends TestCase
{
    public function test_public_ip_literals_are_used_as_they_are(): void
    {
        $resolver = new HostResolver;

        $this->assertSame('8.8.8.8', $resolver->publicAddress('8.8.8.8'));
        $this->assertSame('2001:4860:4860::8888', $resolver->publicAddress('2001:4860:4860::8888'));
    }

    public function test_private_and_reserved_addresses_are_refused(): void
    {
        $resolver = new HostResolver;

        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.1', '169.254.169.254', '100.64.0.1', '::1', 'fd00::1', 'localhost'] as $host) {
            $this->assertNull($resolver->publicAddress($host), $host);
        }
    }

    public function test_names_are_validated(): void
    {
        $resolver = new HostResolver;

        $this->assertTrue($resolver->isValidName('ftp.example.com'));
        $this->assertTrue($resolver->isValidName('2001:db8::1'));
        $this->assertFalse($resolver->isValidName('ftp.example.com/path'));
        $this->assertFalse($resolver->isValidName('user@ftp.example.com'));
    }
}
