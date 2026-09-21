<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Grant;

use App\Module\OAuth\Grant\LoopbackRedirectUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LoopbackRedirectUriTest extends TestCase
{
    #[DataProvider('matching')]
    public function test_a_loopback_uri_matches_on_any_port(string $requested, string $registered): void
    {
        self::assertTrue(LoopbackRedirectUri::isRegistered($requested, [$registered]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function matching(): iterable
    {
        yield 'localhost with a port' => ['http://localhost:53712/callback', 'http://localhost/callback'];
        yield 'ipv4 with a port' => ['http://127.0.0.1:4000/callback', 'http://127.0.0.1/callback'];
        yield 'ipv6 with a port' => ['http://[::1]:4000/callback', 'http://[::1]/callback'];
        yield 'another registered port' => ['http://localhost:1/callback', 'http://localhost:2/callback'];
        yield 'exact' => ['http://localhost/callback', 'http://localhost/callback'];
        yield 'same query' => ['http://localhost:9/cb?x=1', 'http://localhost/cb?x=1'];
        yield 'uppercase host' => ['http://LOCALHOST:9/callback', 'http://localhost/callback'];
    }

    #[DataProvider('refused')]
    public function test_everything_else_must_match_exactly(string $requested, string $registered): void
    {
        self::assertFalse(LoopbackRedirectUri::isRegistered($requested, [$registered]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'another path' => ['http://localhost:9/other', 'http://localhost/callback'];
        yield 'another loopback host' => ['http://127.0.0.1:9/callback', 'http://localhost/callback'];
        yield 'another query' => ['http://localhost:9/callback?x=2', 'http://localhost/callback?x=1'];
        yield 'an added query' => ['http://localhost:9/callback?x=2', 'http://localhost/callback'];
        yield 'a fragment' => ['http://localhost:9/callback#x', 'http://localhost/callback'];
        yield 'userinfo' => ['http://evil@localhost:9/callback', 'http://localhost/callback'];
        yield 'https loopback on another port' => ['https://localhost:9/callback', 'https://localhost/callback'];
        yield 'a public host on another port' => ['https://client.example:444/callback', 'https://client.example/callback'];
        yield 'a public host with the loopback path' => ['http://localhost.evil.example:9/callback', 'http://localhost/callback'];
        yield 'a trailing slash' => ['https://client.example/callback/', 'https://client.example/callback'];
        yield 'a registered public host' => ['http://localhost:9/callback', 'https://client.example/callback'];
    }

    public function test_a_public_uri_matches_exactly(): void
    {
        self::assertTrue(LoopbackRedirectUri::isRegistered('https://client.example/callback', ['https://other.example/cb', 'https://client.example/callback']));
    }
}
