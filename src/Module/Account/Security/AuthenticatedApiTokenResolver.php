<?php

declare(strict_types=1);

namespace App\Module\Account\Security;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Repository\ApiTokenRepository;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reads back the ApiToken that authenticated a request, from the id
 * ApiTokenAuthenticator leaves on the security token. Null whenever the request
 * was not token-authenticated.
 */
final readonly class AuthenticatedApiTokenResolver
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ApiTokenRepository $apiTokens,
    ) {
    }

    public function current(): ?ApiToken
    {
        return $this->forSecurityToken($this->tokenStorage->getToken());
    }

    /**
     * A Voter is handed the security token it must judge, which is not
     * necessarily the one in storage.
     */
    public function forSecurityToken(?TokenInterface $securityToken): ?ApiToken
    {
        if (null === $securityToken || !$securityToken->hasAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR)) {
            return null;
        }

        $apiTokenId = $securityToken->getAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR);

        // Uuid::isValid because the contract is "null when unresolvable": the
        // authenticator only ever writes a real id, but any authenticator could
        // set this attribute and a malformed one must not become a 500.
        if (!is_string($apiTokenId) || !Uuid::isValid($apiTokenId)) {
            return null;
        }

        return $this->apiTokens->find(Uuid::fromString($apiTokenId));
    }
}
