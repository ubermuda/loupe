<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RegenerateProjectMcpTokenControllerTest extends WebTestCase
{
    public function test_owner_regenerates_mcp_token_and_sees_the_new_raw_token_once(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'mcp-regen-a@example.com');
        $project = new Project($owner, 'regen-app');

        [$oldToken, $oldRaw] = ApiToken::issue($owner, 'MCP: regen-app', ApiTokenScope::Mcp);
        $project->mcpToken = $oldToken;
        $em->persist($oldToken);
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $oldTokenId = $oldToken->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/connect');
        $client->submitForm('Regenerate');

        self::assertResponseRedirects('/projects/'.$projectId.'/connect');
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $fresh);
        self::assertNotNull($fresh->mcpToken);
        self::assertSame(ApiTokenScope::Mcp, $fresh->mcpToken->scope);
        self::assertNotSame((string) $oldTokenId, (string) $fresh->mcpToken->id, 'a fresh token must replace the old one');
        self::assertNull($em->find(ApiToken::class, $oldTokenId), 'the previous token must be revoked');

        // The new raw token is flashed exactly once, and it is a *different* secret.
        $client->followRedirect();
        $content = (string) $client->getResponse()->getContent();
        if (1 !== preg_match('/[0-9a-f]{64}/', $content, $matches)) {
            self::fail('the regenerated raw token must be shown');
        }
        self::assertTrue($fresh->mcpToken->matches($matches[0]), 'flashed raw token must match the new stored hash');
        self::assertFalse($fresh->mcpToken->matches($oldRaw), 'the old secret must no longer be valid');

        // ...and gone on the next request.
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/site-review');
        self::assertStringNotContainsString($matches[0], (string) $client->getResponse()->getContent());
    }

    public function test_non_owner_cannot_regenerate(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'mcp-regen-b@example.com');
        $other = $this->user($em, 'mcp-regen-c@example.com');
        $project = new Project($owner, 'not-yours-regen');

        [$token] = ApiToken::issue($owner, 'MCP: not-yours-regen', ApiTokenScope::Mcp);
        $project->mcpToken = $token;
        $em->persist($token);
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $tokenId = $token->id;

        $client->loginUser($other);
        // GET a page the non-owner can access to establish BrowserKit history for the
        // same-origin CSRF check, then POST the regenerate route with the cookie sentinel.
        $client->request(Request::METHOD_GET, '/projects');
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/mcp-token/regenerate', ['_csrf_token' => 'csrf-token']);

        self::assertResponseStatusCodeSame(403);
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $fresh);
        self::assertSame((string) $tokenId, (string) $fresh->mcpToken?->id, 'the token must be unchanged');
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }
}
