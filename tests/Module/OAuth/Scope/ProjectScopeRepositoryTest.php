<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Scope;

use App\Module\OAuth\Scope\GrantedScope;
use App\Tests\Support\OAuthScenario;
use League\Bundle\OAuth2ServerBundle\Entity\Scope;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ProjectScopeRepositoryTest extends KernelTestCase
{
    private ScopeRepositoryInterface $scopes;
    private OAuthScenario $scenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scopes = static::getContainer()->get('league.oauth2_server.repository.scope');
        $this->scenario = new OAuthScenario(static::getContainer());
        $this->scenario->createClient();
    }

    public function test_it_accepts_the_scope_of_an_existing_project_only(): void
    {
        $project = $this->scenario->createProject($this->scenario->createUser('owner@example.com'), 'Site');

        self::assertSame('project:'.$project->id, $this->scopes->getScopeEntityByIdentifier('project:'.$project->id)?->getIdentifier());
        self::assertNull($this->scopes->getScopeEntityByIdentifier(GrantedScope::projectScope(Uuid::v7())));
        self::assertSame('mcp', $this->scopes->getScopeEntityByIdentifier('mcp')?->getIdentifier());
        self::assertNull($this->scopes->getScopeEntityByIdentifier('email'));
    }

    public function test_finalize_carries_the_project_scope_past_the_client_scope_list(): void
    {
        $user = $this->scenario->createUser('owner@example.com');
        $project = $this->scenario->createProject($user, 'Site');

        $finalized = $this->scopes->finalizeScopes([self::scope('mcp'), self::scope('project:'.$project->id)], 'refresh_token', $this->client(), (string) $user->id);

        self::assertSame(['mcp', 'project:'.$project->id], array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $finalized));
    }

    public function test_finalize_refuses_a_project_the_user_does_not_own(): void
    {
        $user = $this->scenario->createUser('owner@example.com');
        $stranger = $this->scenario->createUser('stranger@example.com');
        $project = $this->scenario->createProject($stranger, 'Site');

        $this->expectException(OAuthServerException::class);
        $this->scopes->finalizeScopes([self::scope('mcp'), self::scope('project:'.$project->id)], 'refresh_token', $this->client(), (string) $user->id);
    }

    public function test_finalize_refuses_a_suspended_user(): void
    {
        $user = $this->scenario->createUser('owner@example.com');
        $user->suspendedAt = new \DateTimeImmutable();

        $this->expectException(OAuthServerException::class);
        $this->scopes->finalizeScopes([self::scope('agent')], 'refresh_token', $this->client(), (string) $user->id);
    }

    public function test_finalize_refuses_a_grant_with_no_project_for_mcp(): void
    {
        $user = $this->scenario->createUser('owner@example.com');

        $this->expectException(OAuthServerException::class);
        $this->scopes->finalizeScopes([self::scope('mcp')], 'refresh_token', $this->client(), (string) $user->id);
    }

    private function client(): \League\OAuth2\Server\Entities\ClientEntityInterface
    {
        $repository = static::getContainer()->get(ClientRepositoryInterface::class);

        return $repository->getClientEntity(OAuthScenario::CLIENT_ID) ?? throw new \LogicException('client fixture missing');
    }

    /** @param non-empty-string $identifier */
    private static function scope(string $identifier): Scope
    {
        $scope = new Scope();
        $scope->setIdentifier($identifier);

        return $scope;
    }
}
