<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Splits bearer traffic between the two authenticators on the token firewalls.
 * A static API token is 64 hex characters and an OAuth access token is a JWT,
 * so the shapes never overlap. The split must be exact: the first authenticator
 * that supports a request answers its failure, and no other one is tried.
 */
final class BearerToken
{
    private const string JWT_SHAPE = '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/';

    public static function of(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }

    public static function isJwt(string $token): bool
    {
        return 1 === preg_match(self::JWT_SHAPE, $token);
    }
}
