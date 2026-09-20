<?php

declare(strict_types=1);

namespace App\Module\Project\Security;

use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Security\AuthenticatedApiTokenResolver;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Security\AuthenticatedCredential;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Resolves the Project the current request is acting for, from the credential
 * that authenticated it. Null when the request carries no credential or the
 * credential is bound to no project.
 */
final readonly class AuthenticatedProjectResolver
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private AuthenticatedApiTokenResolver $apiTokens,
        private ProjectRepository $projects,
    ) {
    }

    /** The project whose site-review credential authenticated this request. */
    public function resolveWidgetProject(): ?Project
    {
        return $this->resolve($this->tokenStorage->getToken(), ApiTokenScope::SiteReview);
    }

    /** The project whose MCP credential authenticated this request. */
    public function resolveMcpProject(): ?Project
    {
        return $this->resolve($this->tokenStorage->getToken(), ApiTokenScope::Mcp);
    }

    /**
     * The project bound to the MCP credential carried by a specific security token.
     *
     * A Voter is handed the security token it must judge, which is not
     * necessarily the one in storage, so the binding is resolved from the
     * argument rather than from TokenStorage.
     */
    public function resolveMcpProjectFor(?TokenInterface $securityToken): ?Project
    {
        return $this->resolve($securityToken, ApiTokenScope::Mcp);
    }

    private function resolve(?TokenInterface $securityToken, ApiTokenScope $scope): ?Project
    {
        $credential = AuthenticatedCredential::of($securityToken);
        if (null === $credential) {
            return null;
        }

        if (null !== $credential->projectId) {
            return $scope->role() === $credential->scopeRole ? $this->projects->find($credential->projectId) : null;
        }

        // A static token names no project. The project column that holds the
        // token is the binding, and that column already implies the scope.
        $apiToken = $this->apiTokens->forSecurityToken($securityToken);
        if (null === $apiToken) {
            return null;
        }

        return ApiTokenScope::Mcp === $scope
            ? $this->projects->findOneByMcpToken($apiToken)
            : $this->projects->findOneByWidgetToken($apiToken);
    }
}
