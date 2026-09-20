<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\ClientMetadata;

use App\Module\OAuth\ClientMetadata\ClientIdUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientIdUrlTest extends TestCase
{
    public function test_a_valid_url_maps_to_a_stable_32_character_client_identifier(): void
    {
        $url = ClientIdUrl::parse('https://claude.ai/oauth/claude-code-client-metadata');

        self::assertNotNull($url);
        self::assertSame('claude.ai', $url->host);
        self::assertSame(32, \strlen($url->identifier));
        self::assertStringStartsWith(ClientIdUrl::IDENTIFIER_PREFIX, $url->identifier);
        self::assertSame($url->identifier, ClientIdUrl::parse('https://claude.ai/oauth/claude-code-client-metadata')?->identifier);
        self::assertNotSame($url->identifier, ClientIdUrl::parse('https://claude.ai/oauth/other')?->identifier);
    }

    public function test_only_an_https_url_is_a_candidate(): void
    {
        self::assertTrue(ClientIdUrl::isCandidate('https://x.example/a'));
        self::assertTrue(ClientIdUrl::isCandidate('HTTPS://x.example/a'));
        self::assertFalse(ClientIdUrl::isCandidate('test-client'));
        self::assertFalse(ClientIdUrl::isCandidate('http://x.example/a'));
    }

    #[DataProvider('refused')]
    public function test_it_refuses_a_url_that_is_not_a_plain_https_document_address(string $url): void
    {
        self::assertNull(ClientIdUrl::parse($url));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'http' => ['http://client.example/meta'];
        yield 'uppercase scheme' => ['HTTPS://client.example/meta'];
        yield 'uppercase host' => ['https://Client.example/meta'];
        yield 'no path' => ['https://client.example'];
        yield 'root path' => ['https://client.example/'];
        yield 'userinfo' => ['https://user@client.example/meta'];
        yield 'password' => ['https://user:pass@client.example/meta'];
        yield 'port' => ['https://client.example:8443/meta'];
        yield 'default port' => ['https://client.example:443/meta'];
        yield 'query' => ['https://client.example/meta?x=1'];
        yield 'fragment' => ['https://client.example/meta#x'];
        yield 'dot segment' => ['https://client.example/a/../meta'];
        yield 'single dot segment' => ['https://client.example/a/./meta'];
        yield 'trailing dot segment' => ['https://client.example/a/..'];
        yield 'encoded dot segment' => ['https://client.example/a/%2e%2e/meta'];
        yield 'ipv4 literal' => ['https://160.79.104.10/meta'];
        yield 'ipv6 literal' => ['https://[2607:6bc0::10]/meta'];
        yield 'decimal ip' => ['https://2130706433/meta'];
        yield 'hex ip' => ['https://0x7f.1/meta'];
        yield 'short ip' => ['https://127.1/meta'];
        yield 'single label' => ['https://localhost/meta'];
        yield 'trailing dot host' => ['https://client.example./meta'];
        yield 'backslash' => ['https://client.example\@evil.example/meta'];
        yield 'space' => ['https://client.example/me ta'];
        yield 'too long' => ['https://client.example/'.str_repeat('a', 300)];
    }
}
