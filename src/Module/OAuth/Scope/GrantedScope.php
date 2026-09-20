<?php

declare(strict_types=1);

namespace App\Module\OAuth\Scope;

use App\Module\Account\Entity\ApiTokenScope;
use Symfony\Component\Uid\Uuid;

/**
 * What one OAuth grant allows: exactly one base scope, the same set a static
 * API token carries, plus the project it is bound to. The project travels as a
 * scope, project:<uuid>, so it survives every refresh with no extra table.
 * The mcp and site-review scopes need a project, and agent takes none.
 */
final readonly class GrantedScope
{
    public const string PROJECT_PREFIX = 'project:';

    private function __construct(
        public ApiTokenScope $scope,
        public ?Uuid $projectId,
    ) {
    }

    public static function needsProject(ApiTokenScope $scope): bool
    {
        return ApiTokenScope::Agent !== $scope;
    }

    /** @return non-empty-string */
    public static function projectScope(Uuid $projectId): string
    {
        return self::PROJECT_PREFIX.$projectId->toRfc4122();
    }

    /** The project a project:<uuid> scope names, or null for any other scope. */
    public static function projectIdOf(string $scope): ?Uuid
    {
        if (!str_starts_with($scope, self::PROJECT_PREFIX)) {
            return null;
        }

        $id = substr($scope, \strlen(self::PROJECT_PREFIX));

        return Uuid::isValid($id) && Uuid::fromString($id)->toRfc4122() === $id ? Uuid::fromString($id) : null;
    }

    /**
     * Null when the scopes do not form one valid grant.
     *
     * @param list<string> $scopes
     */
    public static function fromScopes(array $scopes): ?self
    {
        $base = [];
        $projects = [];
        foreach (array_unique($scopes) as $scope) {
            $projectId = self::projectIdOf($scope);
            if (null !== $projectId) {
                $projects[] = $projectId;
                continue;
            }

            $base[] = ApiTokenScope::tryFrom($scope);
        }

        if (1 !== \count($base) || null === $base[0] || \count($projects) > 1) {
            return null;
        }

        $projectId = $projects[0] ?? null;
        if (self::needsProject($base[0]) !== (null !== $projectId)) {
            return null;
        }

        return new self($base[0], $projectId);
    }
}
