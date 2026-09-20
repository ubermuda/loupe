<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Service;

use App\Module\OAuth\Service\RedirectUris;
use PHPUnit\Framework\TestCase;

final class RedirectUrisTest extends TestCase
{
    public function test_the_origin_keeps_scheme_host_and_port_and_drops_the_rest(): void
    {
        self::assertSame('https://client.example', RedirectUris::origin('https://client.example/callback?x=1'));
        self::assertSame('http://127.0.0.1:33418', RedirectUris::origin('http://127.0.0.1:33418/callback'));
        self::assertSame('com.example.app:/callback', RedirectUris::origin('com.example.app:/callback?x=1'));
    }

    public function test_the_warning_shows_only_when_every_uri_is_loopback(): void
    {
        self::assertTrue(RedirectUris::allLoopback(['http://localhost:1234/cb', 'http://127.0.0.1/cb', 'http://[::1]:8080/cb', 'http://127.8.9.10/cb']));
        self::assertFalse(RedirectUris::allLoopback(['http://localhost/cb', 'https://client.example/cb']));
        self::assertFalse(RedirectUris::allLoopback(['https://localhost.evil.example/cb']));
        self::assertFalse(RedirectUris::allLoopback(['https://127.0.0.1.evil.example/cb']));
        self::assertFalse(RedirectUris::allLoopback([]));
    }
}
