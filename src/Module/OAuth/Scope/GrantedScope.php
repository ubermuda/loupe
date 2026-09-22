<?php

declare(strict_types=1);

namespace App\Module\OAuth\Scope;

use Symfony\Component\Uid\Uuid;

/**
 * What one OAuth grant allows: one or more base scopes, plus the projects it
 * may act on. The binding travels as a scope, so it survives every refresh with
 * no extra table.
 *
 * A grant carries several base scopes because one credential has to reach
 * several firewalls. The CLI needs `agent` for the bridge endpoints and `mcp`
 * for the MCP endpoint, and it stores one login.
 *
 * Two bindings exist. project:<uuid> names one project and freezes it at
 * consent. `projects` means every project the user owns, read per request, so a
 * project created after the grant is included and a deleted one disappears. A
 * grant carries one binding or the other, never both.
 *
 * The mcp and site-review scopes need a binding, and agent takes none.
 */
final readonly class GrantedScope
{
    public const string PROJECT_PREFIX = 'project:';

    /** Every project the user owns, resolved per request rather than at consent. */
    public const string ALL_PROJECTS = 'projects';

    /** @param non-empty-list<ApiScope> $scopes */
    private function __construct(
        public array $scopes,
        public ?Uuid $projectId,
        public bool $allProjects = false,
    ) {
    }

    public function allows(ApiScope $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }

    /** @return non-empty-list<string> */
    public function roles(): array
    {
        return array_values(array_unique(array_map(static fn (ApiScope $scope): string => $scope->role(), $this->scopes)));
    }

    public static function needsProject(ApiScope $scope): bool
    {
        return ApiScope::Agent !== $scope;
    }

    /**
     * A grant needs a binding when any of its scopes does. The agent scope
     * alone takes none, and it rides along with one that does.
     *
     * @param list<ApiScope> $scopes
     */
    public static function anyNeedsProject(array $scopes): bool
    {
        return array_any($scopes, fn ($scope) => self::needsProject($scope));
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
     * `projects` is a binding rather than a scope a token carries, so it is
     * taken out before the base scopes are parsed. Left in, it reaches
     * ApiScope::tryFrom(), comes back null, and refuses every grant that
     * names it.
     *
     * @param list<string> $scopes
     */
    public static function fromScopes(array $scopes): ?self
    {
        $base = [];
        $projects = [];
        $allProjects = false;
        foreach (array_unique($scopes) as $scope) {
            if (self::ALL_PROJECTS === $scope) {
                $allProjects = true;
                continue;
            }

            $projectId = self::projectIdOf($scope);
            if (null !== $projectId) {
                $projects[] = $projectId;
                continue;
            }

            $parsed = ApiScope::tryFrom($scope);
            if (null === $parsed) {
                return null;
            }
            $base[] = $parsed;
        }

        if ([] === $base || \count($projects) > 1) {
            return null;
        }

        $projectId = $projects[0] ?? null;
        // One binding or the other. Both would leave two answers to the
        // question of which project a request acts on.
        if ($allProjects && null !== $projectId) {
            return null;
        }
        if (self::anyNeedsProject($base) !== (null !== $projectId || $allProjects)) {
            return null;
        }

        return new self($base, $projectId, $allProjects);
    }
}
