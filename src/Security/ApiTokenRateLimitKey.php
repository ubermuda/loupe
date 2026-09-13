<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Account\Security\ApiTokenAuthenticator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The bucket key of a limiter that allows one agent a budget per API token. A
 * request with no resolved token falls back to its client address.
 */
final readonly class ApiTokenRateLimitKey
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function forRequest(Request $request): string
    {
        $securityToken = $this->tokenStorage->getToken();

        if (null !== $securityToken && $securityToken->hasAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR)) {
            $apiTokenId = $securityToken->getAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR);
            if (\is_string($apiTokenId)) {
                return 'token:'.$apiTokenId;
            }
        }

        return 'ip:'.((string) $request->getClientIp());
    }
}
