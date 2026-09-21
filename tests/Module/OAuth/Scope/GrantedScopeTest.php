<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Scope;

use App\Module\OAuth\Scope\ApiScope;
use App\Module\OAuth\Scope\GrantedScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrantedScopeTest extends TestCase
{
    private const string PROJECT = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public function test_a_project_bound_scope_carries_its_project(): void
    {
        $granted = GrantedScope::fromScopes(['mcp', 'project:'.self::PROJECT]);

        self::assertNotNull($granted);
        self::assertSame([ApiScope::Mcp], $granted->scopes);
        self::assertSame(self::PROJECT, $granted->projectId?->toRfc4122());
    }

    public function test_the_agent_scope_stands_alone(): void
    {
        $granted = GrantedScope::fromScopes(['agent']);

        self::assertNotNull($granted);
        self::assertSame([ApiScope::Agent], $granted->scopes);
        self::assertNull($granted->projectId);
    }

    public function test_the_projects_binding_covers_every_project_of_the_owner(): void
    {
        $granted = GrantedScope::fromScopes(['mcp', 'projects']);

        self::assertNotNull($granted);
        self::assertSame([ApiScope::Mcp], $granted->scopes);
        self::assertTrue($granted->allProjects);
        self::assertNull($granted->projectId);
    }

    public function test_a_project_bound_grant_does_not_cover_every_project(): void
    {
        $granted = GrantedScope::fromScopes(['mcp', 'project:'.self::PROJECT]);

        self::assertNotNull($granted);
        self::assertFalse($granted->allProjects);
    }

    public function test_the_projects_binding_is_not_read_as_a_base_scope(): void
    {
        // `projects` is no ApiScope case. Read as one it comes back null
        // and refuses the grant, whichever order the scopes arrive in.
        $granted = GrantedScope::fromScopes(['projects', 'site-review']);

        self::assertNotNull($granted);
        self::assertSame([ApiScope::SiteReview], $granted->scopes);
        self::assertTrue($granted->allProjects);
    }

    public function test_a_grant_carries_several_base_scopes(): void
    {
        $granted = GrantedScope::fromScopes(['agent', 'mcp', 'projects']);

        self::assertNotNull($granted);
        self::assertSame([ApiScope::Agent, ApiScope::Mcp], $granted->scopes);
        self::assertTrue($granted->allows(ApiScope::Mcp));
        self::assertTrue($granted->allows(ApiScope::Agent));
        self::assertFalse($granted->allows(ApiScope::SiteReview));
        self::assertTrue($granted->allProjects);
    }

    public function test_the_roles_cover_every_scope_the_grant_carries(): void
    {
        $granted = GrantedScope::fromScopes(['agent', 'mcp', 'projects']);

        self::assertNotNull($granted);
        self::assertSame(['ROLE_API_AGENT', 'ROLE_API_MCP'], $granted->roles());
    }

    public function test_a_duplicate_scope_yields_one_role(): void
    {
        $granted = GrantedScope::fromScopes(['agent', 'agent']);

        self::assertNotNull($granted);
        self::assertSame(['ROLE_API_AGENT'], $granted->roles());
    }

    /**
     * The agent scope needs no binding, but a grant that also carries mcp does,
     * so the question is about the set rather than about one scope.
     */
    public function test_a_set_needs_a_binding_when_any_scope_does(): void
    {
        self::assertNull(GrantedScope::fromScopes(['agent', 'mcp']));
        self::assertNotNull(GrantedScope::fromScopes(['agent', 'mcp', 'projects']));
    }

    /** @param list<string> $scopes */
    #[DataProvider('invalidGrants')]
    public function test_an_invalid_grant_is_refused(array $scopes): void
    {
        self::assertNull(GrantedScope::fromScopes($scopes));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidGrants(): iterable
    {
        yield 'no scope' => [[]];
        yield 'mcp with no project' => [['mcp']];
        yield 'site-review with no project' => [['site-review']];
        yield 'agent with a project' => [['agent', 'project:'.self::PROJECT]];
        yield 'two projects' => [['mcp', 'project:'.self::PROJECT, 'project:0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c']];
        yield 'unknown scope' => [['email']];
        yield 'a project alone' => [['project:'.self::PROJECT]];
        yield 'both bindings at once' => [['mcp', 'projects', 'project:'.self::PROJECT]];
        yield 'agent with every project' => [['agent', 'projects']];
        yield 'every project alone' => [['projects']];
    }

    public function test_only_a_canonical_uuid_is_a_project_scope(): void
    {
        self::assertNull(GrantedScope::projectIdOf('project:not-a-uuid'));
        self::assertNull(GrantedScope::projectIdOf('project:'.strtoupper(self::PROJECT)));
        self::assertNull(GrantedScope::projectIdOf('mcp'));
        self::assertSame(self::PROJECT, GrantedScope::projectIdOf('project:'.self::PROJECT)?->toRfc4122());
    }
}
