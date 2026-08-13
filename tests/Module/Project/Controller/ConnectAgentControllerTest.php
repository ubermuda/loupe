<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Mcp\DocumentHighlightTool;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use Mcp\Server\Builder;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ConnectAgentControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    /**
     * The page's tool list is written by hand so each description can be
     * translated copy rather than a docblock, which means nothing stops it
     * drifting from what the server actually exposes — it had gone stale at
     * seven of sixteen. Comparing against the registry is what makes adding an
     * MCP tool fail here until the page mentions it.
     */
    public function test_the_page_lists_every_tool_the_server_registers(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'connect-tools@example.com');
        $project = new Project($owner, 'connect-site-tools');
        $em->persist($project);
        // Parity is between the page and the registry, so the flag-gated tools
        // have to be on for both sides to be comparable at all.
        static::getContainer()->get(FeatureFlagRepository::class)
            ->findAllIndexed()[DocumentHighlightTool::FLAG]->value = true;
        // The tool list only renders once a token exists; without one the page
        // shows the mint step instead and this would compare against nothing.
        [$token] = ApiToken::issue($owner, 'MCP: connect-site-tools', ApiTokenScope::Mcp);
        $project->mcpToken = $token;
        $em->persist($token);
        $em->flush();
        $em->clear();

        // The loaders populate the registry when the server is built, so an
        // unbuilt registry reports no tools at all and this would pass vacuously.
        $builder = static::getContainer()->get('mcp.server.builder');
        self::assertInstanceOf(Builder::class, $builder);
        $builder->build();
        $registry = static::getContainer()->get('mcp.registry');
        self::assertInstanceOf(RegistryInterface::class, $registry);

        // getTools() is typed as the registry's whole element union, so the name
        // is read behind a real check rather than an assumed shape.
        $registered = [];
        foreach ($registry->getTools() as $tool) {
            self::assertInstanceOf(Tool::class, $tool);
            $registered[] = $tool->name;
        }
        self::assertNotEmpty($registered);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/connect');

        self::assertResponseIsSuccessful();
        $listed = $crawler->filter('.lp-tools__name')->each(
            static fn ($node): string => trim($node->text()),
        );

        sort($registered);
        sort($listed);
        self::assertSame($registered, $listed);
    }

    public function test_a_tool_whose_flag_is_off_is_not_listed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'connect-gated@example.com');
        $project = new Project($owner, 'connect-site-gated');
        $em->persist($project);
        [$token] = ApiToken::issue($owner, 'MCP: connect-site-gated', ApiTokenScope::Mcp);
        $project->mcpToken = $token;
        $em->persist($token);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/connect');

        self::assertResponseIsSuccessful();
        $listed = $crawler->filter('.lp-tools__name')->each(
            static fn ($node): string => trim($node->text()),
        );
        // Guard: an empty list would satisfy the assertion below without proving
        // anything about the gate.
        self::assertNotEmpty($listed);
        self::assertNotContains(DocumentHighlightTool::NAME, $listed);
    }

    public function test_without_a_token_the_step_offers_only_a_way_to_create_one(): void
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

        // Both steps rendered, so what is missing below is the template's choice
        // rather than a page that never got as far as drawing them.
        self::assertCount(2, $crawler->filter('.lp-connect-step'));

        // No MCP token yet → the creation form is present, no revoke form.
        self::assertCount(1, $crawler->filter('form[action$="/mcp-token"]'));
        self::assertCount(0, $crawler->filter('form[action*="/revoke"]'));

        // Everything an agent would be configured with is useless without a token
        // to put in it, so none of it is shown until one exists: no endpoint field,
        // no .mcp.json snippet, no tool list.
        self::assertCount(0, $crawler->filter('.lp-connect-field__value'));
        self::assertCount(0, $crawler->filter('.lp-code-dark'));
        self::assertCount(0, $crawler->filter('.lp-tools__name'));
    }

    public function test_an_existing_mcp_token_unlocks_the_endpoint_snippet_and_tool_list(): void
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

        // Token bound → label displayed, a revoke form present, and no way to
        // create a second one.
        self::assertStringContainsString('MCP: connect-site-b', $crawler->text());
        self::assertGreaterThanOrEqual(1, $crawler->filter('form[action*="/revoke"]')->count());
        self::assertCount(0, $crawler->filter('form[action$="/mcp-token"]'));

        // The token's scope is stated on its own meta line.
        self::assertStringContainsString('project scoped', $crawler->filter('.lp-connect-field__meta')->first()->text());

        // The global MCP endpoint path renders somewhere among the fields (the host
        // differs under test, so only the path is matched, and the field's position
        // among the others is not part of the promise).
        $fieldValues = $crawler->filter('.lp-connect-field__value')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $node): string => $node->text(),
        );
        self::assertNotEmpty(array_filter($fieldValues, static fn (string $value): bool => str_contains($value, '/mcp')));

        // The two copyable configurations the token unlocks: the .mcp.json block
        // and the CLI one-liner. The widget step has no token here, so its snippet
        // is not among them.
        self::assertCount(2, $crawler->filter('.lp-code-dark'));

        // The tool list renders here; which tools belong on it is asserted once,
        // against the server's registry, in the parity test above. Restating the
        // names in a second place is what let them go stale to begin with.
        self::assertNotEmpty($crawler->filter('.lp-tools__name'));
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
