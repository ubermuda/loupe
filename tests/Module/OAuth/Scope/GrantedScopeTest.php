<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Scope;

use App\Module\Account\Entity\ApiTokenScope;
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
        self::assertSame(ApiTokenScope::Mcp, $granted->scope);
        self::assertSame(self::PROJECT, $granted->projectId?->toRfc4122());
    }

    public function test_the_agent_scope_stands_alone(): void
    {
        $granted = GrantedScope::fromScopes(['agent']);

        self::assertNotNull($granted);
        self::assertSame(ApiTokenScope::Agent, $granted->scope);
        self::assertNull($granted->projectId);
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
        yield 'two base scopes' => [['mcp', 'agent', 'project:'.self::PROJECT]];
        yield 'two projects' => [['mcp', 'project:'.self::PROJECT, 'project:0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c']];
        yield 'unknown scope' => [['email']];
        yield 'a project alone' => [['project:'.self::PROJECT]];
    }

    public function test_only_a_canonical_uuid_is_a_project_scope(): void
    {
        self::assertNull(GrantedScope::projectIdOf('project:not-a-uuid'));
        self::assertNull(GrantedScope::projectIdOf('project:'.strtoupper(self::PROJECT)));
        self::assertNull(GrantedScope::projectIdOf('mcp'));
        self::assertSame(self::PROJECT, GrantedScope::projectIdOf('project:'.self::PROJECT)?->toRfc4122());
    }
}
