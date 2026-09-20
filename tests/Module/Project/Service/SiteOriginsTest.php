<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Service;

use App\Module\Project\Service\SiteOrigins;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiteOriginsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function origins(): iterable
    {
        yield 'plain https' => ['https://example.com', 'https://example.com'];
        yield 'upper case' => ['HTTPS://Example.COM', 'https://example.com'];
        yield 'trailing slash' => ['https://example.com/', 'https://example.com'];
        yield 'default port dropped' => ['https://example.com:443', 'https://example.com'];
        yield 'other port kept' => ['https://example.com:8443', 'https://example.com:8443'];
        yield 'surrounding space' => ['  https://example.com  ', 'https://example.com'];
        yield 'internationalised host' => ['https://bücher.example', 'https://xn--bcher-kva.example'];
        yield 'http on localhost' => ['http://localhost:3000', 'http://localhost:3000'];
        yield 'http on a localhost subdomain' => ['http://shop.localhost', 'http://shop.localhost'];
        yield 'http on loopback' => ['http://127.0.0.1:8080', 'http://127.0.0.1:8080'];
        yield 'http on IPv6 loopback' => ['http://[::1]:8080', 'http://[::1]:8080'];
    }

    #[DataProvider('origins')]
    public function test_it_serialises_an_origin_the_way_a_browser_does(string $input, string $expected): void
    {
        self::assertSame($expected, SiteOrigins::normalise($input));
    }

    /** @return iterable<string, array{string}> */
    public static function notOrigins(): iterable
    {
        yield 'empty' => [''];
        yield 'no scheme' => ['example.com'];
        yield 'a path' => ['https://example.com/shop'];
        yield 'a query' => ['https://example.com/?a=1'];
        yield 'a fragment' => ['https://example.com/#top'];
        yield 'credentials' => ['https://user:pass@example.com'];
        yield 'another scheme' => ['ftp://example.com'];
        yield 'a javascript URL' => ['javascript:alert(1)'];
        yield 'http on a public host' => ['http://example.com'];
        yield 'a space in the host' => ['https://exa mple.com'];
        yield 'a wildcard' => ['https://*.example.com'];
    }

    #[DataProvider('notOrigins')]
    public function test_it_refuses_a_value_that_is_not_a_usable_origin(string $input): void
    {
        self::assertNull(SiteOrigins::normalise($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function patterns(): iterable
    {
        yield 'a concrete origin stays one' => ['https://example.com', 'https://example.com'];
        yield 'a wildcard host' => ['https://*.example.com', 'https://*.example.com'];
        yield 'upper case' => ['HTTPS://*.Example.COM', 'https://*.example.com'];
        yield 'trailing slash' => ['https://*.example.com/', 'https://*.example.com'];
        yield 'default port dropped' => ['https://*.example.com:443', 'https://*.example.com'];
        yield 'other port kept' => ['https://*.example.com:8443', 'https://*.example.com:8443'];
        yield 'internationalised remainder' => ['https://*.bücher.example', 'https://*.xn--bcher-kva.example'];
        yield 'a worktree host' => ['https://*.loupe.dev.localhost', 'https://*.loupe.dev.localhost'];
        yield 'http on a loopback remainder' => ['http://*.dev.localhost', 'http://*.dev.localhost'];
    }

    #[DataProvider('patterns')]
    public function test_it_accepts_a_wildcard_pattern(string $input, string $expected): void
    {
        self::assertSame($expected, SiteOrigins::normalisePattern($input));
    }

    /** @return iterable<string, array{string}> */
    public static function notPatterns(): iterable
    {
        yield 'a bare star' => ['*'];
        yield 'a star host' => ['https://*'];
        yield 'a star host with a port' => ['https://*:8443'];
        yield 'a public suffix' => ['https://*.com'];
        yield 'a two-label public suffix' => ['https://*.co.uk'];
        yield 'a single label' => ['https://*.localhost'];
        yield 'a star that is not the first label' => ['https://a.*.example.com'];
        yield 'a star inside a label' => ['https://*example.com'];
        yield 'a star beside a label' => ['https://*a.example.com'];
        yield 'two stars' => ['https://*.*.example.com'];
        yield 'a path' => ['https://*.example.com/shop'];
        yield 'http on a public remainder' => ['http://*.example.com'];
    }

    #[DataProvider('notPatterns')]
    public function test_it_refuses_a_wildcard_it_cannot_bound(string $input): void
    {
        self::assertNull(SiteOrigins::normalisePattern($input));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function originMatches(): iterable
    {
        yield 'one label under the wildcard' => ['https://*.example.com', 'https://a.example.com', true];
        yield 'another label under the wildcard' => ['https://*.example.com', 'https://staging.example.com', true];
        yield 'two labels under the wildcard' => ['https://*.example.com', 'https://b.a.example.com', false];
        yield 'the bare domain' => ['https://*.example.com', 'https://example.com', false];
        yield 'an empty label' => ['https://*.example.com', 'https://.example.com', false];
        yield 'a host that only ends the same way' => ['https://*.example.com', 'https://notexample.com', false];
        yield 'another scheme' => ['https://*.example.com', 'http://a.example.com', false];
        yield 'another port' => ['https://*.example.com', 'https://a.example.com:8443', false];
        yield 'the same port' => ['https://*.example.com:8443', 'https://a.example.com:8443', true];
        yield 'a worktree host' => ['https://*.loupe.dev.localhost', 'https://agent-x1.loupe.dev.localhost', true];
        yield 'a host below a worktree host' => ['https://*.loupe.dev.localhost', 'https://a.b.loupe.dev.localhost', false];
        yield 'a concrete pattern and the same origin' => ['https://example.com', 'https://example.com', true];
        yield 'a concrete pattern and another origin' => ['https://example.com', 'https://a.example.com', false];
    }

    #[DataProvider('originMatches')]
    public function test_it_matches_one_label_under_a_wildcard(string $pattern, string $origin, bool $expected): void
    {
        self::assertSame($expected, SiteOrigins::matches($pattern, $origin));
    }

    public function test_the_comparison_ignores_the_case_of_the_origin(): void
    {
        self::assertTrue(SiteOrigins::matches('https://*.example.com', 'HTTPS://A.Example.COM'));
    }

    public function test_a_list_allows_an_origin_when_one_entry_matches(): void
    {
        $list = ['https://shop.example.com', 'https://*.loupe.dev.localhost'];

        self::assertTrue(SiteOrigins::allows($list, 'https://shop.example.com'));
        self::assertTrue(SiteOrigins::allows($list, 'https://agent-x1.loupe.dev.localhost'));
        self::assertFalse(SiteOrigins::allows($list, 'https://evil.example'));
        self::assertFalse(SiteOrigins::allows([], 'https://shop.example.com'));
    }
}
