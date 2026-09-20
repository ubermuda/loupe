<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\ClientMetadata;

use App\Module\OAuth\ClientMetadata\ClientIdUrl;
use App\Module\OAuth\ClientMetadata\ConfiguredTrustedClientIds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfiguredTrustedClientIdsTest extends TestCase
{
    private const string CLAUDE = 'https://claude.ai/oauth/claude-code-client-metadata';

    #[DataProvider('trusted')]
    public function test_it_trusts_a_listed_client(array $entries, string $clientId): void
    {
        self::assertTrue(self::trust($entries, $clientId));
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function trusted(): iterable
    {
        yield 'the exact url' => [[self::CLAUDE], self::CLAUDE];
        yield 'a trailing slash in the entry' => [[self::CLAUDE.'/'], self::CLAUDE];
        yield 'one of several entries' => [['https://other.example/a', self::CLAUDE], self::CLAUDE];
        yield 'an origin the operator vouches for' => [['https://claude.ai'], self::CLAUDE];
    }

    #[DataProvider('untrusted')]
    public function test_it_does_not_trust_anything_else(array $entries, string $clientId): void
    {
        self::assertFalse(self::trust($entries, $clientId));
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function untrusted(): iterable
    {
        yield 'no entries at all' => [[], self::CLAUDE];
        yield 'another document on a shared host' => [['https://cdn.example/gh/loupe/cimd.json'], 'https://cdn.example/gh/attacker/cimd.json'];
        yield 'a host-only entry, which would trust every document on that host' => [['claude.ai'], self::CLAUDE];
        yield 'a host-only entry with a slash' => [['claude.ai/'], self::CLAUDE];
        yield 'a lookalike path on another host' => [[self::CLAUDE], 'https://evil.example/claude.ai/oauth/claude-code-client-metadata'];
        yield 'a host that starts with a trusted one' => [['https://claude.ai'], 'https://claude.ai.evil.example/oauth/metadata'];
        yield 'a path that starts with a trusted one' => [[self::CLAUDE], self::CLAUDE.'-evil'];
        yield 'an http entry' => [['http://claude.ai/oauth/claude-code-client-metadata'], self::CLAUDE];
        yield 'an empty entry' => [[''], self::CLAUDE];
    }

    /** @param list<string> $entries */
    private static function trust(array $entries, string $clientId): bool
    {
        $url = ClientIdUrl::parse($clientId) ?? throw new \LogicException('the test client id parses');

        return new ConfiguredTrustedClientIds($entries)->isTrusted($url);
    }
}
