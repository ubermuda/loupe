<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Messenger\PostPullRequestNotice;
use App\Module\Board\Repository\PullRequestNoticeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener]
final readonly class MarkPullRequestNoticeFailedOnFinalFailure
{
    public function __construct(
        private PullRequestNoticeRepository $pullRequestNotices,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof PostPullRequestNotice || $event->willRetry()) {
            return;
        }

        // A failed flush closes the entity manager, and the worker must keep running.
        if (!$this->em->isOpen()) {
            $this->logger->warning('board.pull_request_notice_mark_skipped', ['noticeId' => (string) $message->noticeId]);

            return;
        }

        try {
            $notice = $this->pullRequestNotices->find($message->noticeId);
            if (null === $notice || PullRequestCommentState::Pending !== $notice->state) {
                return;
            }

            $notice->state = PullRequestCommentState::Failed;
            $notice->failedAt = $this->clock->now();
            $notice->cause ??= 'unknown';
            $this->em->flush();

            $this->logger->error('board.pull_request_notice_gave_up', [
                'noticeId' => (string) $notice->id,
                'projectId' => (string) $notice->project->id,
                'noticeKey' => $notice->noticeKey,
                'attempts' => $notice->attempts,
                'cause' => $notice->cause,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('board.pull_request_notice_mark_failed', [
                'noticeId' => (string) $message->noticeId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
