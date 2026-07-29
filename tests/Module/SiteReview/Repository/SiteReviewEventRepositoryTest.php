<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewEvent;
use App\Module\SiteReview\Repository\SiteReviewEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SiteReviewEventRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SiteReviewEventRepository $siteReviewEvents;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $siteReviewEvents = self::getContainer()->get(SiteReviewEventRepository::class);
        self::assertInstanceOf(SiteReviewEventRepository::class, $siteReviewEvents);
        $this->siteReviewEvents = $siteReviewEvents;
    }

    public function test_claim_takes_only_events_still_owed_to_an_agent(): void
    {
        $project = $this->project('claim-a@example.com');
        $due = $this->event($project);
        $published = $this->event($project);
        $published->markPublished();
        $collectOnly = $this->event($project, forwardable: false);
        $this->em->flush();

        $claimed = $this->siteReviewEvents->claimDueForPublish(10, new \DateTimeImmutable(), $this->inFiveMinutes());

        self::assertSame(
            [(string) $due->id],
            array_map(static fn (SiteReviewEvent $event): string => (string) $event->id, $claimed),
            'A published row is settled, and an unforwardable one must never be delivered.',
        );
        self::assertNotNull($published->id);
        self::assertNotNull($collectOnly->id);
    }

    public function test_a_claimed_event_is_not_handed_to_the_next_claim(): void
    {
        $project = $this->project('claim-b@example.com');
        $this->event($project);
        $this->em->flush();

        $now = new \DateTimeImmutable();
        $first = $this->siteReviewEvents->claimDueForPublish(10, $now, $this->inFiveMinutes($now));
        $second = $this->siteReviewEvents->claimDueForPublish(10, $now, $this->inFiveMinutes($now));

        // The lease, not a held lock, is what makes this true — and it is the
        // same mechanism that keeps two concurrent workers off the same row.
        self::assertCount(1, $first);
        self::assertSame([], $second);
    }

    public function test_a_claim_whose_lease_expired_becomes_due_again(): void
    {
        $project = $this->project('claim-c@example.com');
        $this->event($project);
        $this->em->flush();

        $claimedAt = new \DateTimeImmutable('-1 hour');
        self::assertCount(1, $this->siteReviewEvents->claimDueForPublish(10, $claimedAt, $this->inFiveMinutes($claimedAt)));

        // A worker that died mid-batch must not strand its rows for good.
        $now = new \DateTimeImmutable();
        self::assertCount(1, $this->siteReviewEvents->claimDueForPublish(10, $now, $this->inFiveMinutes($now)));
    }

    public function test_claim_honours_the_limit_and_takes_the_oldest_first(): void
    {
        $project = $this->project('claim-d@example.com');
        $first = $this->event($project);
        $this->event($project);
        $this->em->flush();

        $claimed = $this->siteReviewEvents->claimDueForPublish(1, new \DateTimeImmutable(), $this->inFiveMinutes());

        self::assertCount(1, $claimed);
        self::assertSame((string) $first->id, (string) $claimed[0]->id);
    }

    public function test_the_unsent_list_matches_what_the_drain_would_claim(): void
    {
        $project = $this->project('unsent-a@example.com');
        $other = $this->project('unsent-b@example.com');
        $owed = $this->event($project);
        $published = $this->event($project);
        $published->markPublished();
        $this->event($project, forwardable: false);
        $this->event($other);
        $this->em->flush();

        $unsent = $this->siteReviewEvents->findUnsentForProject($project);

        self::assertCount(1, $unsent);
        self::assertSame((string) $owed->id, (string) $unsent[0]->id);
        self::assertSame(1, $this->siteReviewEvents->countUnsent($project));
        self::assertSame(2, $this->siteReviewEvents->countUnsent());

        $projectNames = array_map(
            static fn (Project $candidate): string => $candidate->name,
            $this->siteReviewEvents->findProjectsWithUnsent(),
        );
        self::assertContains($project->name, $projectNames);
        self::assertContains($other->name, $projectNames);
    }

    private function inFiveMinutes(?\DateTimeImmutable $from = null): \DateTimeImmutable
    {
        return ($from ?? new \DateTimeImmutable())->add(new \DateInterval('PT5M'));
    }

    private function event(Project $project, bool $forwardable = true): SiteReviewEvent
    {
        $event = new SiteReviewEvent($project, 'https://app/topic', '{}', $forwardable);
        $this->em->persist($event);

        return $event;
    }

    /** @param non-empty-string $email */
    private function project(string $email): Project
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'outbox-'.bin2hex(random_bytes(4)));
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
