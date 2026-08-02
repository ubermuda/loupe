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

final class ConnectAgentControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    public function test_owner_sees_endpoint_mint_form_and_tools(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'connect-a@example.com');
        $project = new Project($owner, 'connect-site-a');
        $em->persist($project);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/connect');

        self::assertResponseIsSuccessful();

        // The global MCP endpoint path renders (host differs under test — match the path only).
        self::assertStringContainsString('/mcp', $crawler->filter('.lp-connect-field__value')->first()->text());

        // No MCP token yet → the mint form is present, no revoke form.
        self::assertCount(1, $crawler->filter('form[action$="/mcp-token"]'));
        self::assertCount(0, $crawler->filter('form[action*="/revoke"]'));

        // All 7 agent tools render.
        $toolNames = $crawler->filter('.lp-tools__name')->each(fn ($node) => $node->text());
        self::assertSame([
            'document_create',
            'document_list',
            'document_get',
            'document_get_review',
            'document_revise',
            'site_review_get',
            'site_review_mark_comment_addressed',
        ], $toolNames);
    }

    public function test_minted_mcp_token_shows_label_and_revoke_and_no_mint_form(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'connect-b@example.com');
        $project = new Project($owner, 'connect-site-b');
        $em->persist($project);

        [$token] = ApiToken::issue($owner, 'MCP: connect-site-b', ApiTokenScope::Mcp);
        $project->mcpToken = $token;
        $em->persist($token);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/connect');

        self::assertResponseIsSuccessful();

        // Minted → label displayed, a revoke form present, and NO mint form.
        self::assertStringContainsString('MCP: connect-site-b', $crawler->text());
        self::assertGreaterThanOrEqual(1, $crawler->filter('form[action*="/revoke"]')->count());
        self::assertCount(0, $crawler->filter('form[action$="/mcp-token"]'));

        // The "project-scoped" tag renders next to the token.
        self::assertCount(1, $crawler->filter('.lp-connect-tag'));
    }

    public function test_revoked_mcp_token_disappears_and_mint_form_reappears(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'connect-d@example.com');
        $project = new Project($owner, 'connect-site-d');
        $em->persist($project);

        [$token] = ApiToken::issue($owner, 'MCP: connect-site-d', ApiTokenScope::Mcp);
        $project->mcpToken = $token;
        $em->persist($token);
        $em->flush();
        $projectId = $project->id;
        $tokenId = $token->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects');

        $client->request(Request::METHOD_POST, '/account/api-tokens/'.(string) $tokenId.'/revoke', [
            '_csrf_token' => 'csrf-token',
        ]);
        self::assertResponseRedirects();
        // Consume the "has been revoked" flash on an unrelated page first — it
        // legitimately echoes the old label once, which would otherwise pollute
        // the assertion below on the very next page rendered.
        $client->followRedirect();

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/connect');
        self::assertResponseIsSuccessful();

        // Revoked → the token row still exists but is no longer bound to the
        // project, so the page reverts to the mint form exactly as if no token had
        // ever been minted: no label, no revoke form.
        self::assertStringNotContainsString('MCP: connect-site-d', $crawler->text());
        self::assertCount(1, $crawler->filter('form[action$="/mcp-token"]'));
        self::assertCount(0, $crawler->filter('form[action*="/revoke"]'));
    }

    public function test_non_owner_is_denied(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'connect-c@example.com');
        $project = new Project($owner, 'connect-site-c');
        $em->persist($project);
        $other = $this->user($em, 'connect-c-other@example.com');
        $em->flush();
        $em->clear();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/connect');

        self::assertResponseStatusCodeSame(403);
    }
}
