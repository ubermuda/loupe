<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\PullRequestComment;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\EventListener\MarkFixRunCommentFailedOnFinalFailure;
use App\Module\Board\Messenger\PostFixRunComment;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;

final class MarkFixRunCommentFailedOnFinalFailureTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private MockClock $clock;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock('2026-09-29 12:00:00');
        self::getContainer()->set('clock', $this->clock);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('final-failure');
    }

    public function test_the_final_failure_marks_the_comment_failed_and_keeps_the_cause(): void
    {
        $comment = $this->pending('api_failed_http_status_502');

        $this->listener()($this->failed($comment));

        $this->em->refresh($comment);
        self::assertSame(PullRequestCommentState::Failed, $comment->state);
        self::assertSame('api_failed_http_status_502', $comment->cause);
        self::assertEquals($this->clock->now(), $comment->failedAt);
    }

    public function test_a_final_failure_with_no_recorded_cause_says_unknown(): void
    {
        $comment = $this->pending(null);

        $this->listener()($this->failed($comment));

        $this->em->refresh($comment);
        self::assertSame(PullRequestCommentState::Failed, $comment->state);
        self::assertSame('unknown', $comment->cause);
    }

    public function test_a_failure_that_will_retry_leaves_the_comment_pending(): void
    {
        $comment = $this->pending('api_failed_transport');
        $event = $this->failed($comment);
        $event->setForRetry();

        $this->listener()($event);

        $this->em->refresh($comment);
        self::assertSame(PullRequestCommentState::Pending, $comment->state);
        self::assertNull($comment->failedAt);
    }

    public function test_a_posted_comment_stays_posted(): void
    {
        $comment = $this->pending(null);
        $comment->state = PullRequestCommentState::Posted;
        $this->em->flush();

        $this->listener()($this->failed($comment));

        $this->em->refresh($comment);
        self::assertSame(PullRequestCommentState::Posted, $comment->state);
    }

    public function test_another_message_is_ignored(): void
    {
        $comment = $this->pending(null);

        $this->listener()(new WorkerMessageFailedEvent(new Envelope(new \stdClass()), 'async', new \RuntimeException('boom')));

        $this->em->refresh($comment);
        self::assertSame(PullRequestCommentState::Pending, $comment->state);
    }

    private function pending(?string $cause): PullRequestComment
    {
        $comment = new PullRequestComment(
            project: $this->project,
            runId: Uuid::v7(),
            cardId: Uuid::v7(),
            forge: 'github',
            repository: 'acme/widgets',
            number: 5,
            headSha: null,
            reason: null,
            createdAt: $this->clock->now(),
        );
        $comment->cause = $cause;
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    private function failed(PullRequestComment $comment): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent(
            new Envelope(new PostFixRunComment($comment->id ?? throw new \LogicException('A flushed comment has an id.'))),
            'async',
            new \RuntimeException('boom'),
        );
    }

    private function listener(): MarkFixRunCommentFailedOnFinalFailure
    {
        $listener = self::getContainer()->get(MarkFixRunCommentFailedOnFinalFailure::class);
        self::assertInstanceOf(MarkFixRunCommentFailedOnFinalFailure::class, $listener);

        return $listener;
    }
}
