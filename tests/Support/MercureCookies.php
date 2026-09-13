<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** Reads the Mercure subscriber cookie a response sets. */
trait MercureCookies
{
    private static function findMercureCookie(Response $response): ?Cookie
    {
        $cookies = array_values(array_filter(
            $response->headers->getCookies(),
            static fn (Cookie $cookie): bool => 'mercureAuthorization' === $cookie->getName(),
        ));
        Assert::assertLessThanOrEqual(1, \count($cookies), 'the response sets more than one Mercure cookie');

        return $cookies[0] ?? null;
    }

    /** @return list<string>|null the topics the cookie's token may subscribe to */
    private static function subscribedTopics(Response $response): ?array
    {
        $cookie = self::findMercureCookie($response);
        if (null === $cookie) {
            return null;
        }
        Assert::assertTrue($cookie->isHttpOnly());
        Assert::assertSame('/.well-known/mercure', $cookie->getPath());

        $parts = explode('.', (string) $cookie->getValue());
        Assert::assertCount(3, $parts);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        Assert::assertIsArray($claims);
        Assert::assertIsArray($claims['mercure'] ?? null);
        Assert::assertSame([], $claims['mercure']['publish'] ?? []);
        Assert::assertIsArray($claims['mercure']['subscribe'] ?? null);

        /* @var list<string> */
        return $claims['mercure']['subscribe'];
    }
}
