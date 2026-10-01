<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Entity\PullRequestNotice;
use App\Module\Board\EventListener\MarkPullRequestNoticeFailedOnFinalFailure;
use App\Module\Board\Messenger\PostPullRequestNotice;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;

final class MarkPullRequestNoticeFailedOnFinalFailureTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private MockClock $clock;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock('2026-10-01 12:00:00');
        self::getContainer()->set('clock', $this->clock);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('notice-final-failure');
    }

    public function test_the_final_failure_marks_the_notice_failed_and_keeps_the_cause(): void
    {
        $notice = $this->pending('api_failed_http_status_502');

        $this->listener()($this->failed($notice));

        $this->em->refresh($notice);
        self::assertSame(PullRequestCommentState::Failed, $notice->state);
        self::assertSame('api_failed_http_status_502', $notice->cause);
        self::assertEquals($this->clock->now(), $notice->failedAt);
    }

    public function test_a_failure_that_will_retry_leaves_the_notice_pending(): void
    {
        $notice = $this->pending(null);
        $event = $this->failed($notice);
        $event->setForRetry();

        $this->listener()($event);

        $this->em->refresh($notice);
        self::assertSame(PullRequestCommentState::Pending, $notice->state);
        self::assertNull($notice->failedAt);
    }

    private function pending(?string $cause): PullRequestNotice
    {
        $notice = new PullRequestNotice($this->project, Uuid::v7(), 'github', 'acme/widgets', 5, 'stale-approval:abc1234', $this->clock->now());
        $notice->cause = $cause;
        $this->em->persist($notice);
        $this->em->flush();

        return $notice;
    }

    private function failed(PullRequestNotice $notice): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent(
            new Envelope(new PostPullRequestNotice($notice->id ?? throw new \LogicException('A flushed notice has an id.'))),
            'async',
            new \RuntimeException('boom'),
        );
    }

    private function listener(): MarkPullRequestNoticeFailedOnFinalFailure
    {
        $listener = self::getContainer()->get(MarkPullRequestNoticeFailedOnFinalFailure::class);
        self::assertInstanceOf(MarkPullRequestNoticeFailedOnFinalFailure::class, $listener);

        return $listener;
    }
}
