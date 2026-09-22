<?php

declare(strict_types=1);

namespace App\Module\OAuth\Token;

use App\Module\OAuth\Scope\ApiScope;
use App\Module\OAuth\Service\McpResource;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Binds every mcp token to the MCP endpoint. The scope decides the audience,
 * so a refresh keeps it and the resource parameter needs no storage.
 */
#[AsDecorator('league.oauth2_server.repository.access_token')]
final readonly class ResourceBoundAccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private AccessTokenRepositoryInterface $inner,
        private McpResource $mcpResource,
    ) {
    }

    #[\Override]
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $identifiers = array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $scopes);
        if (!\in_array(ApiScope::Mcp->value, $identifiers, true)) {
            return $this->inner->getNewToken($clientEntity, $scopes, $userIdentifier);
        }

        $token = new ResourceBoundAccessToken($this->mcpResource->uri);
        $token->setClient($clientEntity);
        if (null !== $userIdentifier && '' !== $userIdentifier) {
            $token->setUserIdentifier($userIdentifier);
        }
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }

        return $token;
    }

    #[\Override]
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->inner->persistNewAccessToken($accessTokenEntity);
    }

    #[\Override]
    public function revokeAccessToken(string $tokenId): void
    {
        $this->inner->revokeAccessToken($tokenId);
    }

    #[\Override]
    public function isAccessTokenRevoked(string $tokenId): bool
    {
        return $this->inner->isAccessTokenRevoked($tokenId);
    }
}
