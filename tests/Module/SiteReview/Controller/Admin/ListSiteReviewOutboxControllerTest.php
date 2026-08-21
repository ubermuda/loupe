<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller\Admin;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewEvent;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ListSiteReviewOutboxControllerTest extends WebTestCase
{
    public function test_admin_sees_undelivered_events_across_every_project(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $admin = $this->user($em, 'outbox-admin-a@admin-test.example.com', ['ROLE_ADMIN']);
        $first = $this->projectWithStuckEvent($em, 'outbox-admin-p1@example.com');
        $second = $this->projectWithStuckEvent($em, 'outbox-admin-p2@example.com');
        $em->flush();
        $em->clear();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/site-review-outbox');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-outbox-event-id]');
        self::assertCount(2, $rows);
        $rowText = $this->rowText($rows);
        self::assertStringContainsString($first->name, $rowText);
        self::assertStringContainsString($second->name, $rowText);
    }

    public function test_the_project_filter_narrows_the_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $admin = $this->user($em, 'outbox-admin-b@admin-test.example.com', ['ROLE_ADMIN']);
        $wanted = $this->projectWithStuckEvent($em, 'outbox-admin-p3@example.com');
        $ignored = $this->projectWithStuckEvent($em, 'outbox-admin-p4@example.com');
        $em->flush();
        $em->clear();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/site-review-outbox?project='.$wanted->id);

        self::assertResponseIsSuccessful();
        // Scoped to the rows: every project is also named in the filter select,
        // so a body-wide assertion could never fail.
        $rows = $crawler->filter('[data-outbox-event-id]');
        self::assertCount(1, $rows);
        self::assertStringContainsString($wanted->name, $this->rowText($rows));
        self::assertStringNotContainsString($ignored->name, $this->rowText($rows));
    }

    public function test_an_unknown_project_filter_widens_to_everything(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $admin = $this->user($em, 'outbox-admin-c@admin-test.example.com', ['ROLE_ADMIN']);
        $this->projectWithStuckEvent($em, 'outbox-admin-p5@example.com');
        $em->flush();
        $em->clear();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/site-review-outbox?project=not-a-uuid');

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-outbox-event-id]')->count());
    }

    public function test_a_logged_in_non_admin_gets_403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = $this->user($em, 'outbox-admin-plain@admin-test.example.com');
        $em->flush();
        $em->clear();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/admin/site-review-outbox');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_anonymous_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/admin/site-review-outbox');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    /** Crawler::text() reads the first node only, so rows must be joined by hand. */
    private function rowText(Crawler $rows): string
    {
        return implode(' ', $rows->each(static fn (Crawler $row): string => $row->text()));
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function user(EntityManagerInterface $em, string $email, array $roles = []): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $user->roles = $roles;
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }

    /** @param non-empty-string $email */
    private function projectWithStuckEvent(EntityManagerInterface $em, string $email): Project
    {
        $project = new Project($this->user($em, $email), 'admin-outbox-'.bin2hex(random_bytes(4)));
        $em->persist($project);
        $em->persist(new SiteReviewEvent($project, 'https://app/topic', '{}'));

        return $project;
    }
}
