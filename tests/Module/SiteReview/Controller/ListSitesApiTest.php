<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ListSitesApiTest extends WebTestCase
{
    public function test_lists_only_callers_sites(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();

        $owner = $this->user($em, 'list-sites@example.com');
        $site1 = new Project($owner, 'my-site-one');
        $site2 = new Project($owner, 'my-site-two');
        $em->persist($site1);
        $em->persist($site2);

        $other = $this->user($em, 'list-sites-other@example.com');
        $otherSite = new Project($other, 'other-site');
        $em->persist($otherSite);

        $em->flush();

        $raw = AgentCredential::agentToken(static::getContainer(), $owner);

        $client->request(Request::METHOD_GET, '/api/projects',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('sites', $data);

        $returnedIds = array_column($data['sites'], 'id');
        self::assertContains((string) $site1->id, $returnedIds);
        self::assertContains((string) $site2->id, $returnedIds);
        self::assertNotContains((string) $otherSite->id, $returnedIds);
        self::assertCount(2, $data['sites']);
    }

    /** The bridge lists these slugs when a rule file names a project that does not exist. */
    public function test_each_site_carries_its_slug(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();

        $owner = $this->user($em, 'list-sites-slug@example.com');
        $em->persist(new Project($owner, 'My Slugged Site'));
        $em->flush();

        $raw = AgentCredential::agentToken(static::getContainer(), $owner);

        $client->request(Request::METHOD_GET, '/api/projects',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(['my-slugged-site'], array_column($data['sites'], 'slug'));
    }

    public function test_the_old_agent_path_is_gone(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();

        $owner = $this->user($em, 'list-sites-old-path@example.com');
        $em->flush();

        $raw = AgentCredential::agentToken(static::getContainer(), $owner);

        $client->request(Request::METHOD_GET, '/api/agent/sites',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_no_token_is_unauthorized(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/api/projects');
        self::assertResponseStatusCodeSame(401);
    }

    public function test_mcp_scoped_token_is_forbidden(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();

        $user = $this->user($em, 'list-sites-mcp@example.com');
        $project = new Project($user, 'list-mcp-site');
        $em->persist($project);
        $em->flush();

        $raw = AgentCredential::tokenFor(static::getContainer(), $user, 'mcp', $project);

        $client->request(Request::METHOD_GET, '/api/projects',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_site_bound_widget_token_is_forbidden(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();

        // A widget credential holds the site-review scope and one site. It must
        // never widen to the list of sites its owner holds.
        $user = $this->user($em, 'list-sites-widget@example.com');
        $project = new Project($user, 'list-widget-site');
        $em->persist($project);
        $em->flush();

        $raw = AgentCredential::tokenFor(static::getContainer(), $user, 'site-review', $project);

        $client->request(Request::METHOD_GET, '/api/projects',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        // The firewall refuses it on scope, so the answer comes from
        // ApiAccessDeniedHandler rather than from the controller.
        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('insufficient_scope', $data['error'] ?? null);
    }

    /**
     * A site-review grant over every project of its owner is refused too. Scope
     * alone decides here, so the binding is not what keeps a widget out.
     */
    public function test_a_site_review_credential_is_forbidden_the_agent_surface(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();

        $user = $this->user($em, 'list-sites-unbound@example.com');
        $project = new Project($user, 'list-unbound-site');
        $em->persist($project);
        $em->flush();

        $raw = AgentCredential::tokenFor(static::getContainer(), $user, 'site-review', $project);

        $client->request(Request::METHOD_GET, '/api/projects',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('insufficient_scope', $data['error'] ?? null);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'Owner', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }
}
