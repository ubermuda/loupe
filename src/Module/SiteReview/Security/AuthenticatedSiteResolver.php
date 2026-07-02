<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Security;

use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Account\Security\ApiTokenAuthenticator;
use App\Module\SiteReview\Entity\Site;
use App\Module\SiteReview\Repository\SiteRepository;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves the Site the current request is acting for: the Site bound to the
 * ApiToken that authenticated this request. Null when the request was not
 * token-authenticated or the token is not bound to any site.
 */
final readonly class AuthenticatedSiteResolver
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ApiTokenRepository $apiTokens,
        private SiteRepository $sites,
    ) {
    }

    public function resolve(): ?Site
    {
        $securityToken = $this->tokenStorage->getToken();
        if (null === $securityToken) {
            return null;
        }

        if (!$securityToken->hasAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR)) {
            return null;
        }

        $apiTokenId = $securityToken->getAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR);
        if (!is_string($apiTokenId)) {
            return null;
        }

        $apiToken = $this->apiTokens->find(Uuid::fromString($apiTokenId));
        if (null === $apiToken) {
            return null;
        }

        return $this->sites->findOneByToken($apiToken);
    }
}
