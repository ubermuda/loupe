<?php

declare(strict_types=1);

namespace App\Module\Project\Security;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Account\Security\ApiTokenAuthenticator;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves the Project the current request is acting for: the Project bound to
 * the ApiToken that authenticated this request. Null when the request was not
 * token-authenticated or the token is not bound to any project.
 */
final readonly class AuthenticatedProjectResolver
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ApiTokenRepository $apiTokens,
        private ProjectRepository $projects,
    ) {
    }

    /** The project whose site-review widget token authenticated this request. */
    public function resolveWidgetProject(): ?Project
    {
        $apiToken = $this->authenticatedApiToken();

        return null === $apiToken ? null : $this->projects->findOneByWidgetToken($apiToken);
    }

    /** The project whose MCP token authenticated this request. */
    public function resolveMcpProject(): ?Project
    {
        $apiToken = $this->authenticatedApiToken();

        return null === $apiToken ? null : $this->projects->findOneByMcpToken($apiToken);
    }

    private function authenticatedApiToken(): ?ApiToken
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

        return $this->apiTokens->find(Uuid::fromString($apiTokenId));
    }
}
