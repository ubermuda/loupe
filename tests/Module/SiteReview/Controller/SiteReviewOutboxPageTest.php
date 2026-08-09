<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SiteReviewOutboxPageTest extends WebTestCase
{
    public function test_the_owner_sees_only_what_is_still_owed_to_the_agent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $project = $this->project($em, 'outbox-owner@example.com');
        $stuck = new SiteReviewEvent($project, 'https://app/topic', '{}');
        $stuck->recordPublishFailure('hub unreachable', new \DateTimeImmutable());
        $delivered = new SiteReviewEvent($project, 'https://app/topic', '{}');
        $delivered->markPublished();
        $collectOnly = new SiteReviewEvent($project, 'https://app/topic', '{}', false);
        $em->persist($stuck);
        $em->persist($delivered);
        $em->persist($collectOnly);
        $em->flush();
        $owner = $project->owner;
        $stuckId = $stuck->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review/outbox');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-outbox-event-id]'));
        self::assertCount(1, $crawler->filter('[data-outbox-event-id="'.$stuckId.'"]'));
        self::assertStringContainsString('hub unreachable', $crawler->filter('.lp-site-review-list')->text());
    }

    public function test_an_empty_outbox_says_so(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $project = $this->project($em, 'outbox-empty@example.com');
        $owner = $project->owner;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review/outbox');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-outbox-event-id]'));
        self::assertCount(1, $crawler->filter('.lp-empty-state'));
    }

    public function test_a_stranger_cannot_read_another_projects_outbox(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $project = $this->project($em, 'outbox-victim@example.com');
        $stranger = $this->user($em, 'outbox-stranger@example.com');
        $em->flush();
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review/outbox');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_anonymous_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $project = $this->project($em, 'outbox-anon@example.com');
        $em->clear();

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review/outbox');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function test_the_site_review_page_links_to_the_outbox_only_when_something_is_stuck(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $project = $this->project($em, 'outbox-notice@example.com');
        $owner = $project->owner;
        $em->clear();

        $client->loginUser($owner);
        $outboxPath = '/projects/'.$project->id.'/site-review/outbox';
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');
        self::assertCount(0, $crawler->filter('a[href="'.$outboxPath.'"]'));

        $reattached = $em->find(Project::class, $project->id);
        self::assertNotNull($reattached);
        $em->persist(new SiteReviewEvent($reattached, 'https://app/topic', '{}'));
        $em->flush();
        $em->clear();

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');
        self::assertCount(1, $crawler->filter('a[href="'.$outboxPath.'"]'));
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    /** @param non-empty-string $email */
    private function project(EntityManagerInterface $em, string $email): Project
    {
        $project = new Project($this->user($em, $email), 'outbox-'.bin2hex(random_bytes(4)));
        $em->persist($project);
        $em->flush();

        return $project;
    }
}
