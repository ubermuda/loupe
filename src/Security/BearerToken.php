<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/** The bearer credential of a machine request, for the token firewalls. */
final class BearerToken
{
    public static function of(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }
}
