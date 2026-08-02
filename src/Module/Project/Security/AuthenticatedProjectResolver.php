<?php

declare(strict_types=1);

namespace App\Module\Project\Security;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Account\Security\ApiTokenAuthenticator;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
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
        $apiToken = $this->apiTokenFor($this->tokenStorage->getToken());

        return null === $apiToken ? null : $this->projects->findOneByWidgetToken($apiToken);
    }

    /** The project whose MCP token authenticated this request. */
    public function resolveMcpProject(): ?Project
    {
        return $this->resolveMcpProjectFor($this->tokenStorage->getToken());
    }

    /**
     * The project bound to the MCP token carried by a specific security token.
     *
     * A Voter is handed the security token it must judge, which is not
     * necessarily the one in storage, so the binding is resolved from the
     * argument rather than from TokenStorage.
     */
    public function resolveMcpProjectFor(?TokenInterface $securityToken): ?Project
    {
        $apiToken = $this->apiTokenFor($securityToken);

        return null === $apiToken ? null : $this->projects->findOneByMcpToken($apiToken);
    }

    private function apiTokenFor(?TokenInterface $securityToken): ?ApiToken
    {
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
