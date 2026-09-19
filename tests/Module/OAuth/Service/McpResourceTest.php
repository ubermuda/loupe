<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Service;

use App\Module\OAuth\Service\McpResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpResourceTest extends TestCase
{
    public function test_the_canonical_uri_is_the_instance_url_with_the_mcp_path(): void
    {
        self::assertSame('https://loupe.example/mcp', new McpResource('https://loupe.example/')->uri);
        self::assertSame('https://loupe.example/mcp', new McpResource('https://loupe.example')->uri);
        self::assertSame('https://loupe.example/.well-known/oauth-protected-resource/mcp', new McpResource('https://loupe.example')->metadataUrl);
    }

    #[DataProvider('accepted')]
    public function test_it_accepts_the_canonical_uri_in_any_case_of_scheme_and_host(string $value): void
    {
        self::assertTrue(new McpResource('https://loupe.example')->matches($value));
    }

    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        yield 'exact' => ['https://loupe.example/mcp'];
        yield 'uppercase scheme and host' => ['HTTPS://LOUPE.EXAMPLE/mcp'];
        yield 'explicit default port' => ['https://loupe.example:443/mcp'];
    }

    #[DataProvider('refused')]
    public function test_it_refuses_any_other_resource(string $value): void
    {
        self::assertFalse(new McpResource('https://loupe.example')->matches($value));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'trailing slash' => ['https://loupe.example/mcp/'];
        yield 'uppercase path' => ['https://loupe.example/MCP'];
        yield 'other host' => ['https://evil.example/mcp'];
        yield 'host suffix' => ['https://loupe.example.evil.example/mcp'];
        yield 'plain http' => ['http://loupe.example/mcp'];
        yield 'other port' => ['https://loupe.example:8443/mcp'];
        yield 'query' => ['https://loupe.example/mcp?x=1'];
        yield 'fragment' => ['https://loupe.example/mcp#x'];
        yield 'userinfo' => ['https://user@loupe.example/mcp'];
        yield 'the instance root' => ['https://loupe.example'];
        yield 'the api' => ['https://loupe.example/api'];
        yield 'relative' => ['/mcp'];
        yield 'empty' => [''];
    }
}
