<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Security;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Security\ApiTokenAuthenticator;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Security\AuthenticatedCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class AuthenticatedProjectResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AuthenticatedProjectResolver $resolver;
    private TokenStorageInterface $tokenStorage;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $resolver = self::getContainer()->get(AuthenticatedProjectResolver::class);
        self::assertInstanceOf(AuthenticatedProjectResolver::class, $resolver);
        $this->resolver = $resolver;
        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $this->tokenStorage = $tokenStorage;
    }

    public function test_a_static_widget_token_resolves_the_project_that_binds_it(): void
    {
        $project = $this->project('static-widget');
        [$token] = ApiToken::issue($project->owner, 'Widget', ApiTokenScope::SiteReview);
        $this->em->persist($token);
        $project->widgetToken = $token;
        $this->em->flush();

        $this->tokenStorage->setToken($this->staticTokenCredential($project->owner, $token));

        self::assertSame($project, $this->resolver->resolveWidgetProject());
        self::assertNull($this->resolver->resolveMcpProject());
    }

    public function test_a_static_mcp_token_resolves_the_project_that_binds_it(): void
    {
        $project = $this->project('static-mcp');
        [$token] = ApiToken::issue($project->owner, 'MCP', ApiTokenScope::Mcp);
        $this->em->persist($token);
        $project->mcpToken = $token;
        $this->em->flush();

        $securityToken = $this->staticTokenCredential($project->owner, $token);
        $this->tokenStorage->setToken($securityToken);

        self::assertSame($project, $this->resolver->resolveMcpProject());
        self::assertSame($project, $this->resolver->resolveMcpProjectFor($securityToken));
        self::assertNull($this->resolver->resolveWidgetProject());
    }

    public function test_a_credential_that_names_its_project_resolves_it_for_its_own_scope_only(): void
    {
        $project = $this->project('named-mcp');
        $this->em->flush();

        $securityToken = $this->credentialNaming($project, ApiTokenScope::Mcp);
        $this->tokenStorage->setToken($securityToken);

        self::assertSame($project, $this->resolver->resolveMcpProject());
        self::assertSame($project, $this->resolver->resolveMcpProjectFor($securityToken));
        self::assertNull($this->resolver->resolveWidgetProject(), 'an MCP grant must not act as the project widget');
    }

    public function test_a_site_review_credential_that_names_its_project_is_not_an_mcp_binding(): void
    {
        $project = $this->project('named-widget');
        $this->em->flush();

        $securityToken = $this->credentialNaming($project, ApiTokenScope::SiteReview);
        $this->tokenStorage->setToken($securityToken);

        self::assertSame($project, $this->resolver->resolveWidgetProject());
        self::assertNull($this->resolver->resolveMcpProject());
        self::assertNull($this->resolver->resolveMcpProjectFor($securityToken));
    }

    public function test_an_api_token_id_without_a_credential_resolves_nothing(): void
    {
        $project = $this->project('no-credential');
        [$token] = ApiToken::issue($project->owner, 'MCP', ApiTokenScope::Mcp);
        $this->em->persist($token);
        $project->mcpToken = $token;
        $this->em->flush();

        $securityToken = new PostAuthenticationToken($project->owner, 'api', $project->owner->getRoles());
        $securityToken->setAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR, (string) $token->id);
        $this->tokenStorage->setToken($securityToken);

        self::assertNull($this->resolver->resolveMcpProject());
    }

    public function test_a_request_with_no_security_token_resolves_nothing(): void
    {
        self::assertNull($this->resolver->resolveWidgetProject());
        self::assertNull($this->resolver->resolveMcpProject());
        self::assertNull($this->resolver->resolveMcpProjectFor(null));
    }

    private function project(string $name): Project
    {
        $owner = new User(fullName: 'U', email: 'resolver-'.$name.'@example.com', password: 'x');
        $this->em->persist($owner);
        $project = new Project($owner, $name);
        $this->em->persist($project);

        return $project;
    }

    /** The two attributes ApiTokenAuthenticator leaves on the security token. */
    private function staticTokenCredential(User $owner, ApiToken $token): PostAuthenticationToken
    {
        $securityToken = new PostAuthenticationToken($owner, 'api', $owner->getRoles());
        $securityToken->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential((string) $token->id, $token->scope->role()));
        $securityToken->setAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR, (string) $token->id);

        return $securityToken;
    }

    private function credentialNaming(Project $project, ApiTokenScope $scope): PostAuthenticationToken
    {
        $securityToken = new PostAuthenticationToken($project->owner, 'api', $project->owner->getRoles());
        $securityToken->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential('grant-'.$project->name, $scope->role(), $project->id));

        return $securityToken;
    }
}
