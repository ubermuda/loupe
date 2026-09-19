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
}
