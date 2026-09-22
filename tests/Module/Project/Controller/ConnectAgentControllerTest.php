<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Project\Entity\Project;
use App\Module\Review\Mcp\DocumentHighlightTool;
use App\Tests\Support\AcceptedTerms;
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
        AcceptedTerms::stamp($user, static::getContainer());
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
        $installedFlags = static::getContainer()->get(FeatureFlagRepository::class)->findAllIndexed();
        $installedFlags[DocumentHighlightTool::FLAG]->value = true;
        $installedFlags[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;
        $installedFlags[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = true;
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

    public function test_the_agent_step_shows_the_endpoint_the_methods_and_the_skills(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'connect-b@example.com');
        $project = new Project($owner, 'connect-site-b');
        $em->persist($project);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/connect');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#agent-connection'));
        self::assertCount(1, $crawler->filter('#site-review-widget'));

        // The global MCP endpoint path renders among the fields. The host differs
        // under test, so only the path is matched.
        $fieldValues = $crawler->filter('.lp-connect-field__value')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $node): string => $node->text(),
        );
        self::assertNotEmpty(array_filter($fieldValues, static fn (string $value): bool => str_contains($value, '/mcp')));

        // Three copyable configurations, each behind a disclosure that starts
        // shut. The step opens as a list of choices, not three stacked blocks.
        self::assertCount(3, $crawler->filter('#agent-connection .lp-code-dark'));
        self::assertCount(3, $crawler->filter('details.lp-install'));
        self::assertCount(0, $crawler->filter('details.lp-install[open]'));

        // No page may hand out a credential any more, so no snippet carries one.
        self::assertStringNotContainsString('YOUR_TOKEN', $crawler->text());
        self::assertStringNotContainsString('Authorization: Bearer', $crawler->text());

        self::assertCount(2, $crawler->filter('.lp-skills__name'));
        self::assertNotEmpty($crawler->filter('.lp-tools__name'));
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
