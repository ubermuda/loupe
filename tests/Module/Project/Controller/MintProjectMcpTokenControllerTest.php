<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Security\ApiTokenAuthenticator;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class MintProjectMcpTokenControllerTest extends WebTestCase
{
    public function test_owner_mints_mcp_token_and_sees_raw_token_once(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'mcp-mint-a@example.com');
        $project = new Project($owner, 'mcp-app');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/site-review/sites/'.$projectId);
        $client->submitForm('Mint MCP token');

        self::assertResponseRedirects('/site-review/sites/'.$projectId);
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $fresh);
        self::assertNotNull($fresh->mcpToken);
        self::assertSame(ApiTokenScope::Mcp, $fresh->mcpToken->scope);
        self::assertNull($fresh->widgetToken, 'minting the MCP token must not touch the widget binding');

        // The raw token is flashed exactly once: present on the redirect target...
        $client->followRedirect();
        $content = (string) $client->getResponse()->getContent();
        if (1 !== preg_match('/[0-9a-f]{64}/', $content, $matches)) {
            self::fail('flash must contain the raw token');
        }
        self::assertTrue($fresh->mcpToken->matches($matches[0]), 'flashed raw token must match the stored hash');

        // ...and gone on the next request.
        $client->request(Request::METHOD_GET, '/site-review/sites/'.$projectId);
        self::assertStringNotContainsString($matches[0], (string) $client->getResponse()->getContent());
    }

    public function test_non_owner_cannot_mint(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'mcp-mint-b@example.com');
        $other = $this->user($em, 'mcp-mint-c@example.com');
        $project = new Project($owner, 'not-yours');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;

        $client->loginUser($other);
        // GET a page the non-owner can access to establish BrowserKit history for the
        // same-origin CSRF check, then POST the mint route with the cookie sentinel.
        $client->request(Request::METHOD_GET, '/site-review/sites');
        $client->request(Request::METHOD_POST, '/site-review/sites/'.$projectId.'/mcp-token', ['_csrf_token' => 'csrf-token']);

        self::assertResponseStatusCodeSame(403);
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $fresh);
        self::assertNull($fresh->mcpToken);
    }

    public function test_second_mint_is_rejected_with_already_minted_error(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'mcp-mint-d@example.com');
        $project = new Project($owner, 'once-only-mcp');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/site-review/sites/'.$projectId);
        $client->submitForm('Mint MCP token');
        $em->clear();
        $freshAfterFirstMint = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $freshAfterFirstMint);
        $tokenId = $freshAfterFirstMint->mcpToken?->id;
        self::assertNotNull($tokenId);

        // The page now shows Revoke, not Mint — POST the mint route directly to simulate a race.
        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel: the preceding GET establishes
        // BrowserKit history so the Referer header passes the same-origin check automatically.
        $client->request(Request::METHOD_POST, '/site-review/sites/'.$projectId.'/mcp-token', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/site-review/sites/'.$projectId);
        $em->clear();
        $freshAfterSecondMint = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $freshAfterSecondMint);
        self::assertSame((string) $tokenId, (string) $freshAfterSecondMint->mcpToken?->id, 'token must be unchanged');

        $client->followRedirect();
        self::assertStringContainsString(
            'This project already has an MCP token.',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function test_resolver_resolves_the_project_from_the_minted_mcp_token(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'mcp-mint-e@example.com');
        $project = new Project($owner, 'resolve-via-request');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/site-review/sites/'.$projectId);
        $client->submitForm('Mint MCP token');
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $fresh);
        self::assertNotNull($fresh->mcpToken);

        // Simulate a request authenticated by that token: the authenticator stores the
        // ApiToken id as a security-token attribute, which the resolver reads back.
        $securityToken = new PostAuthenticationToken($fresh->owner, 'api', $fresh->owner->getRoles());
        $securityToken->setAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR, (string) $fresh->mcpToken->id);
        $tokenStorage = static::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken($securityToken);

        $resolver = static::getContainer()->get(AuthenticatedProjectResolver::class);
        self::assertInstanceOf(AuthenticatedProjectResolver::class, $resolver);
        $resolved = $resolver->resolveMcpProject();
        self::assertInstanceOf(Project::class, $resolved);
        self::assertSame((string) $projectId, (string) $resolved->id);
        self::assertNull($resolver->resolveWidgetProject(), 'an MCP token must not resolve as a widget binding');
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }
}
