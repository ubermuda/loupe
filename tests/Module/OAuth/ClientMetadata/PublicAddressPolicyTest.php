<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\ClientMetadata;

use App\Module\OAuth\ClientMetadata\PublicAddressPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicAddressPolicyTest extends TestCase
{
    #[DataProvider('public')]
    public function test_a_public_address_is_allowed(string $ip): void
    {
        self::assertTrue(PublicAddressPolicy::isPublic($ip));
    }

    /** @return iterable<string, array{string}> */
    public static function public(): iterable
    {
        yield 'ipv4' => ['160.79.104.10'];
        yield 'ipv6' => ['2607:6bc0::10'];
    }

    #[DataProvider('refused')]
    public function test_a_non_public_address_is_refused(string $ip): void
    {
        self::assertFalse(PublicAddressPolicy::isPublic($ip));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'loopback range' => ['127.8.9.10'];
        yield 'rfc 1918 ten' => ['10.1.2.3'];
        yield 'rfc 1918 172' => ['172.20.0.2'];
        yield 'rfc 1918 192' => ['192.168.1.1'];
        yield 'link-local, cloud metadata' => ['169.254.169.254'];
        yield 'cgnat' => ['100.64.0.1'];
        yield 'this network' => ['0.0.0.0'];
        yield 'ietf protocol assignments' => ['192.0.0.8'];
        yield 'documentation' => ['192.0.2.1'];
        yield 'benchmarking' => ['198.18.0.1'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'broadcast' => ['255.255.255.255'];
        yield 'ipv6 loopback' => ['::1'];
        yield 'ipv6 unspecified' => ['::'];
        yield 'ipv6 unique local' => ['fd00::1'];
        yield 'ipv6 link-local' => ['fe80::1'];
        yield 'ipv6 site-local' => ['fec0::1'];
        yield 'ipv6 multicast' => ['ff02::1'];
        yield 'ipv6 documentation' => ['2001:db8::1'];
        yield 'ipv4-mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'ipv4-mapped public' => ['::ffff:160.79.104.10'];
        yield 'nat64' => ['64:ff9b::a9fe:a9fe'];
        yield '6to4' => ['2002:7f00:1::'];
        yield 'teredo' => ['2001::1'];
        yield 'not an address' => ['localhost'];
        yield 'decimal form' => ['2130706433'];
        yield 'empty' => [''];
    }
}
