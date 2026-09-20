<?php

declare(strict_types=1);

namespace App\Module\OAuth\Scope;

use App\Module\Account\Repository\UserRepository;
use App\Module\Project\Repository\ProjectRepository;
use League\Bundle\OAuth2ServerBundle\Entity\Scope;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Uid\Uuid;

/**
 * Accepts project:<uuid> beside the configured scopes. League validates the
 * scopes again at every token request, so the bundle's own repository would
 * refuse a project scope there: it knows only configured scopes, and it limits
 * each client to its own scope list. This one hands only the base scopes to
 * the bundle and carries the project scope through.
 *
 * It is also the one hook league calls at every code exchange and refresh with
 * the user id, so it refuses a grant whose user is gone or suspended, or whose
 * project the user no longer owns.
 */
#[AsDecorator('league.oauth2_server.repository.scope')]
final readonly class ProjectScopeRepository implements ScopeRepositoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private ScopeRepositoryInterface $inner,
        private ProjectRepository $projects,
        private UserRepository $users,
    ) {
    }

    #[\Override]
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        $projectId = GrantedScope::projectIdOf($identifier);
        if (null === $projectId) {
            return $this->inner->getScopeEntityByIdentifier($identifier);
        }

        if (null === $this->projects->find($projectId)) {
            return null;
        }

        $scope = new Scope();
        $scope->setIdentifier(GrantedScope::projectScope($projectId));

        return $scope;
    }

    #[\Override]
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null,
    ): array {
        $projectScopes = array_values(array_filter($scopes, static fn (ScopeEntityInterface $scope): bool => null !== GrantedScope::projectIdOf($scope->getIdentifier())));
        $baseScopes = array_values(array_filter($scopes, static fn (ScopeEntityInterface $scope): bool => null === GrantedScope::projectIdOf($scope->getIdentifier())));

        $finalized = [...$this->inner->finalizeScopes($baseScopes, $grantType, $clientEntity, $userIdentifier, $authCodeId), ...$projectScopes];

        $granted = GrantedScope::fromScopes(array_values(array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $finalized)));
        if (null === $granted) {
            throw OAuthServerException::invalidScope(implode(' ', array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $finalized)));
        }

        $this->assertUserMayHold($granted, $userIdentifier);

        return $finalized;
    }

    private function assertUserMayHold(GrantedScope $granted, ?string $userIdentifier): void
    {
        $user = null !== $userIdentifier && Uuid::isValid($userIdentifier) ? $this->users->find(Uuid::fromString($userIdentifier)) : null;
        if (null === $user || $user->isSuspended()) {
            throw OAuthServerException::invalidGrant('The account behind this grant cannot use it.');
        }

        if (null === $granted->projectId) {
            return;
        }

        $project = $this->projects->find($granted->projectId);
        if (null === $project || $project->owner->id?->toRfc4122() !== $user->id?->toRfc4122()) {
            throw OAuthServerException::invalidGrant('The project behind this grant is gone.');
        }
    }
}
