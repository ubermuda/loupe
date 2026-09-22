<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Security;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Project\Security\ProjectRefusal;
use App\Security\AuthenticatedCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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

    public function test_a_credential_that_names_its_project_resolves_it_for_its_own_scope_only(): void
    {
        $project = $this->project('named-mcp');
        $this->em->flush();

        $securityToken = $this->credentialNaming($project, ApiScope::Mcp);
        $this->tokenStorage->setToken($securityToken);

        self::assertSame($project, $this->resolver->resolveMcpProject());
        self::assertSame($project, $this->resolver->resolveMcpProjectFor($securityToken));
        self::assertNull($this->resolver->resolveWidgetProject(), 'an MCP grant must not act as the project widget');
    }

    public function test_a_site_review_credential_that_names_its_project_is_not_an_mcp_binding(): void
    {
        $project = $this->project('named-widget');
        $this->em->flush();

        $securityToken = $this->credentialNaming($project, ApiScope::SiteReview);
        $this->tokenStorage->setToken($securityToken);

        self::assertSame($project, $this->resolver->resolveWidgetProject());
        self::assertNull($this->resolver->resolveMcpProject());
        self::assertNull($this->resolver->resolveMcpProjectFor($securityToken));
    }

    public function test_a_grant_covering_every_project_takes_the_one_the_header_names(): void
    {
        $first = $this->project('all-first');
        $second = new Project($first->owner, 'all-second');
        $this->em->persist($second);
        $this->em->flush();

        $this->tokenStorage->setToken($this->credentialCoveringEveryProject($first->owner));
        $this->requestNaming($second);

        self::assertSame($second, $this->resolver->resolveMcpProject());
    }

    public function test_a_grant_covering_every_project_refuses_a_project_of_another_owner(): void
    {
        $mine = $this->project('all-mine');
        $theirs = $this->project('all-theirs');
        $this->em->flush();

        $this->tokenStorage->setToken($this->credentialCoveringEveryProject($mine->owner));
        $this->requestNaming($theirs);

        $resolution = $this->resolver->mcpResolution();

        self::assertNull($resolution->project);
        self::assertSame(ProjectRefusal::HeaderNotCovered, $resolution->refusal);
        self::assertSame([$mine], $resolution->covered);
    }

    public function test_a_grant_covering_one_project_needs_no_header(): void
    {
        $only = $this->project('all-only');
        $this->em->flush();

        $this->tokenStorage->setToken($this->credentialCoveringEveryProject($only->owner));

        self::assertSame($only, $this->resolver->resolveMcpProject());
    }

    public function test_a_grant_covering_several_projects_refuses_without_a_header(): void
    {
        $first = $this->project('several-first');
        $second = new Project($first->owner, 'several-second');
        $this->em->persist($second);
        $this->em->flush();

        $this->tokenStorage->setToken($this->credentialCoveringEveryProject($first->owner));

        $resolution = $this->resolver->mcpResolution();

        self::assertNull($resolution->project);
        self::assertSame(ProjectRefusal::SeveralProjectsAndNoHeader, $resolution->refusal);
        self::assertCount(2, $resolution->covered);
    }

    public function test_a_header_that_is_not_a_project_id_is_refused(): void
    {
        $project = $this->project('bad-header');
        $this->em->flush();

        $this->tokenStorage->setToken($this->credentialCoveringEveryProject($project->owner));
        $this->requestWithHeader('not-a-project');

        self::assertSame(ProjectRefusal::HeaderMalformed, $this->resolver->mcpResolution()->refusal);
    }

    public function test_a_bound_grant_accepts_a_header_naming_its_own_project(): void
    {
        $project = $this->project('bound-same');
        $this->em->flush();

        $this->tokenStorage->setToken($this->credentialNaming($project, ApiScope::Mcp));
        $this->requestNaming($project);

        self::assertSame($project, $this->resolver->resolveMcpProject());
    }

    public function test_a_bound_grant_refuses_a_header_naming_another_project(): void
    {
        $bound = $this->project('bound-own');
        $other = $this->project('bound-other');
        $this->em->flush();

        $this->tokenStorage->setToken($this->credentialNaming($bound, ApiScope::Mcp));
        $this->requestNaming($other);

        $resolution = $this->resolver->mcpResolution();

        self::assertNull($resolution->project);
        self::assertSame(ProjectRefusal::HeaderNotCovered, $resolution->refusal);
    }

    /** A stray header on a widget request refuses rather than being ignored. */
    public function test_a_widget_grant_refuses_a_header_naming_another_project(): void
    {
        $project = $this->project('widget-header');
        $other = $this->project('widget-other');
        $this->em->flush();

        $this->tokenStorage->setToken($this->credentialNaming($project, ApiScope::SiteReview));

        self::assertSame($project, $this->resolver->resolveWidgetProject());

        $this->requestNaming($other);

        self::assertNull($this->resolver->resolveWidgetProject());
    }

    public function test_a_request_with_no_security_token_resolves_nothing(): void
    {
        self::assertNull($this->resolver->resolveWidgetProject());
        self::assertNull($this->resolver->resolveMcpProject());
        self::assertNull($this->resolver->resolveMcpProjectFor(null));
    }

    /** A credential that names neither one project nor every project of its owner. */
    public function test_a_credential_bound_to_nothing_resolves_nothing(): void
    {
        $project = $this->project('unbound');
        $this->em->flush();

        $securityToken = new PostAuthenticationToken($project->owner, 'api', $project->owner->getRoles());
        $securityToken->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential('grant-unbound', [ApiScope::Mcp->role()]));
        $this->tokenStorage->setToken($securityToken);

        self::assertNull($this->resolver->resolveMcpProject());
        self::assertSame(ProjectRefusal::Unbound, $this->resolver->mcpResolution()->refusal);
    }

    private function project(string $name): Project
    {
        $owner = new User(fullName: 'U', email: 'resolver-'.$name.'@example.com', password: 'x');
        $this->em->persist($owner);
        $project = new Project($owner, $name);
        $this->em->persist($project);

        return $project;
    }

    private function credentialCoveringEveryProject(User $owner): PostAuthenticationToken
    {
        $securityToken = new PostAuthenticationToken($owner, 'api', $owner->getRoles());
        $securityToken->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential('grant-all', [ApiScope::Mcp->role()], null, true));

        return $securityToken;
    }

    private function requestNaming(Project $project): void
    {
        $this->requestWithHeader((string) $project->id);
    }

    private function requestWithHeader(string $value): void
    {
        $request = Request::create('/mcp', Request::METHOD_POST);
        $request->headers->set(AuthenticatedProjectResolver::PROJECT_HEADER, $value);
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);
    }

    private function credentialNaming(Project $project, ApiScope $scope): PostAuthenticationToken
    {
        $securityToken = new PostAuthenticationToken($project->owner, 'api', $project->owner->getRoles());
        $securityToken->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential('grant-'.$project->name, [$scope->role()], $project->id));

        return $securityToken;
    }
}
