<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\SubmitReviewCommand;
use App\Module\SiteReview\Command\SubmitReviewHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Repository\SiteReviewEventRepository;
use App\Module\SiteReview\Service\SiteReviewTopicBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class SubmitReviewHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private HubInterface&\PHPUnit\Framework\MockObject\MockObject $hub;
    private SubmitReviewHandler $handler;
    private SiteReviewCommentRepository $comments;
    private SiteReviewEventRepository $events;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $comments = self::getContainer()->get(SiteReviewCommentRepository::class);
        self::assertInstanceOf(SiteReviewCommentRepository::class, $comments);
        $this->comments = $comments;
        $events = self::getContainer()->get(SiteReviewEventRepository::class);
        self::assertInstanceOf(SiteReviewEventRepository::class, $events);
        $this->events = $events;
        $this->hub = $this->createMock(HubInterface::class);
        $topicBuilder = self::getContainer()->get(SiteReviewTopicBuilder::class);
        self::assertInstanceOf(SiteReviewTopicBuilder::class, $topicBuilder);
        $this->handler = new SubmitReviewHandler($this->comments, $this->em, $this->hub, $topicBuilder, new NullLogger());
    }

    public function test_submit_flips_drafts_to_pending_and_publishes_a_payload_free_nudge(): void
    {
        $project = $this->project('submit-a@example.com');
        $this->em->persist(new SiteReviewComment($project, 0, 'one', '.a', 'A', 'https://app/x'));
        $this->em->persist(new SiteReviewComment($project, 1, 'two', '', '', 'https://app/x'));
        $this->em->flush();

        $this->hub->expects($this->once())->method('publish')
            ->with(self::callback(function (Update $update) use ($project): bool {
                $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);

                // Payload-free by design: no review id, no comment count, no site name —
                // the Draft→Pending transition is itself the dedup signal.
                return str_ends_with((string) $update->getTopics()[0], '/projects/'.$project->id.'/site-reviews')
                    && ['type' => 'site_review.submitted'] === $data;
            }))
            ->willReturn('id');

        $count = ($this->handler)(new SubmitReviewCommand($project));

        self::assertSame(2, $count);
        self::assertSame(0, $this->comments->countDraftForProject($project));
        $pending = $this->comments->findPendingForProject($project);
        self::assertCount(2, $pending);

        // Durable outbox row for the published update: written in the same
        // flush as the submit, then marked published once the hub confirmed it.
        $event = $this->events->findOneBy(['project' => $project]);
        self::assertNotNull($event);
        self::assertNotNull($event->publishedAt);
        self::assertNotNull($event->sequence);
    }

    public function test_publish_carries_the_sequence_as_the_sse_id(): void
    {
        $project = $this->project('submit-f@example.com');
        $this->em->persist(new SiteReviewComment($project, 0, 'one', '', '', 'https://app/x'));
        $this->em->flush();

        $capturedId = null;
        $this->hub->expects($this->once())->method('publish')
            ->with(self::callback(function (Update $update) use (&$capturedId): bool {
                $capturedId = $update->getId();

                return true;
            }))
            ->willReturn('id');

        ($this->handler)(new SubmitReviewCommand($project));

        $event = $this->events->findOneBy(['project' => $project]);
        self::assertNotNull($event);
        self::assertNotNull($event->sequence);
        self::assertSame($event->sequence, $capturedId);
    }

    public function test_no_draft_comments_is_a_domain_error(): void
    {
        $project = $this->project('submit-b@example.com');
        $this->hub->expects($this->never())->method('publish');
        $this->expectException(DomainErrors::class);
        ($this->handler)(new SubmitReviewCommand($project));
    }

    public function test_hub_failure_does_not_fail_the_submit(): void
    {
        $project = $this->project('submit-d@example.com');
        $this->em->persist(new SiteReviewComment($project, 0, 'one', '', '', 'https://app/x'));
        $this->em->flush();

        // Published exactly once — never retried, since the hub may have accepted
        // the update before throwing.
        $this->hub->expects($this->once())->method('publish')->willThrowException(new \RuntimeException('hub down'));

        $count = ($this->handler)(new SubmitReviewCommand($project));
        self::assertSame(1, $count);
        self::assertSame(0, $this->comments->countDraftForProject($project));

        // The outbox row survives the failed publish, unpublished — the durable
        // record a lost update leaves behind for a human to replay by hand.
        $event = $this->events->findOneBy(['project' => $project]);
        self::assertNotNull($event);
        self::assertNull($event->publishedAt);
    }

    public function test_addressed_and_resolved_comments_are_not_reflipped(): void
    {
        $project = $this->project('submit-e@example.com');
        $draft = new SiteReviewComment($project, 0, 'draft', '', '', 'https://app/x');
        $resolved = new SiteReviewComment($project, 1, 'resolved', '', '', 'https://app/x');
        $resolved->status = SiteReviewCommentStatus::Resolved;
        $this->em->persist($draft);
        $this->em->persist($resolved);
        $this->em->flush();

        $this->hub->expects($this->once())->method('publish')->willReturn('id');

        $count = ($this->handler)(new SubmitReviewCommand($project));

        self::assertSame(1, $count);
        $this->em->clear();
        $freshResolved = $this->em->find(SiteReviewComment::class, $resolved->id);
        self::assertNotNull($freshResolved);
        self::assertSame(SiteReviewCommentStatus::Resolved, $freshResolved->status);
    }

    /** @param non-empty-string $email */
    private function project(string $email, string $name = 'handler-site'): Project
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, $name);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
