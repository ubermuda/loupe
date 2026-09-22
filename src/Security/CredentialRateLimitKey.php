<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The bucket key of a limiter that allows one agent a budget per credential. A
 * request with no credential falls back to its client address.
 */
final readonly class CredentialRateLimitKey
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function forRequest(Request $request): string
    {
        $credential = AuthenticatedCredential::of($this->tokenStorage->getToken());
        if (null !== $credential) {
            return 'token:'.$credential->id;
        }

        return 'ip:'.((string) $request->getClientIp());
    }
}
