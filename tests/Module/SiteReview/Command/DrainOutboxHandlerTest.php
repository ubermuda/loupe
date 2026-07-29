<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\DrainOutboxCommand;
use App\Module\SiteReview\Command\DrainOutboxHandler;
use App\Module\SiteReview\Entity\SiteReviewEvent;
use App\Module\SiteReview\Repository\SiteReviewEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class DrainOutboxHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private HubInterface&MockObject $hub;
    private DrainOutboxHandler $handler;
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
        $this->hub = $this->createMock(HubInterface::class);
        $this->handler = new DrainOutboxHandler($this->siteReviewEvents, $this->em, $this->hub, new NullLogger());
    }

    public function test_a_stranded_event_is_republished_and_settled(): void
    {
        $project = $this->project('drain-a@example.com');
        $event = $this->event($project);
        $this->em->flush();

        $this->hub->expects($this->once())->method('publish')
            ->with(self::callback(fn (Update $update): bool => ['https://app/topic'] === $update->getTopics()
                    && '{}' === $update->getData()
                    // The sequence rides along as the SSE id so a reconnecting
                    // subscriber can resume from it, exactly as on first publish.
                    && $event->sequence === $update->getId()))
            ->willReturn('id');

        $result = ($this->handler)(new DrainOutboxCommand());

        self::assertSame(1, $result->published);
        self::assertSame(0, $result->failed);

        $this->em->clear();
        $settled = $this->em->find(SiteReviewEvent::class, $event->id);
        self::assertNotNull($settled);
        self::assertNotNull($settled->publishedAt);
        self::assertSame(0, $this->siteReviewEvents->countUnsent($project));
    }

    public function test_a_collect_only_event_is_never_drained(): void
    {
        $project = $this->project('drain-b@example.com');
        $event = $this->event($project, forwardable: false);
        $this->em->flush();

        // The opt-in would be worthless if the drain delivered after the fact
        // what the submit deliberately withheld.
        $this->hub->expects($this->never())->method('publish');

        $result = ($this->handler)(new DrainOutboxCommand());

        self::assertSame(0, $result->published);
        self::assertSame(0, $result->failed);

        $this->em->clear();
        $untouched = $this->em->find(SiteReviewEvent::class, $event->id);
        self::assertNotNull($untouched);
        self::assertNull($untouched->publishedAt);
        self::assertNull($untouched->nextAttemptAt);
    }

    public function test_an_already_published_event_is_not_published_twice(): void
    {
        $project = $this->project('drain-c@example.com');
        $event = $this->event($project);
        $event->markPublished();
        $this->em->flush();

        $this->hub->expects($this->never())->method('publish');

        self::assertSame(0, ($this->handler)(new DrainOutboxCommand())->published);
    }

    public function test_a_failing_publish_records_the_error_and_backs_the_row_off(): void
    {
        $project = $this->project('drain-d@example.com');
        $event = $this->event($project);
        $this->em->flush();

        $this->hub->expects($this->once())->method('publish')
            ->willThrowException(new \RuntimeException('hub unreachable'));

        $result = ($this->handler)(new DrainOutboxCommand());

        self::assertSame(0, $result->published);
        self::assertSame(1, $result->failed);

        $this->em->clear();
        $stuck = $this->em->find(SiteReviewEvent::class, $event->id);
        self::assertNotNull($stuck);
        self::assertNull($stuck->publishedAt, 'A failed publish must leave the row replayable.');
        self::assertSame(1, $stuck->publishAttempts);
        self::assertSame('hub unreachable', $stuck->lastPublishError);
        self::assertNotNull($stuck->nextAttemptAt);
        self::assertGreaterThan(new \DateTimeImmutable(), $stuck->nextAttemptAt);
    }

    public function test_a_backed_off_row_is_left_alone_until_it_is_due(): void
    {
        $project = $this->project('drain-e@example.com');
        $this->event($project);
        $this->em->flush();

        $this->hub->expects($this->once())->method('publish')
            ->willThrowException(new \RuntimeException('hub unreachable'));

        ($this->handler)(new DrainOutboxCommand());

        // Second pass in the same minute: the backoff has not elapsed, so the
        // hub mock's single-call expectation is what proves nothing retried.
        self::assertSame(0, ($this->handler)(new DrainOutboxCommand())->failed);
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
        $project = new Project($user, 'drain-'.bin2hex(random_bytes(4)));
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
