<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Messenger\PostFixRunComment;
use App\Module\Board\Repository\PullRequestCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener]
final readonly class MarkFixRunCommentFailedOnFinalFailure
{
    public function __construct(
        private PullRequestCommentRepository $pullRequestComments,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof PostFixRunComment || $event->willRetry()) {
            return;
        }

        // A failed flush closes the entity manager, and the worker must keep running.
        if (!$this->em->isOpen()) {
            $this->logger->warning('board.fix_run_comment_mark_skipped', ['commentId' => (string) $message->commentId]);

            return;
        }

        try {
            $this->markFailed($message);
        } catch (\Throwable $e) {
            $this->logger->error('board.fix_run_comment_mark_failed', [
                'commentId' => (string) $message->commentId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    private function markFailed(PostFixRunComment $message): void
    {
        $comment = $this->pullRequestComments->find($message->commentId);
        if (null === $comment || PullRequestCommentState::Pending !== $comment->state) {
            return;
        }

        $comment->state = PullRequestCommentState::Failed;
        $comment->failedAt = $this->clock->now();
        $comment->cause ??= 'unknown';
        $this->em->flush();

        $this->logger->error('board.fix_run_comment_gave_up', [
            'commentId' => (string) $comment->id,
            'projectId' => (string) $comment->project->id,
            'cardId' => (string) $comment->cardId,
            'runId' => (string) $comment->runId,
            'attempts' => $comment->attempts,
            'cause' => $comment->cause,
        ]);
    }
}
