<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class PurgeCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private CommentRepository $comments,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(PurgeCommentCommand $command): void
    {
        $comment = $command->comment;
        if (null !== $comment->parent) {
            throw new DomainErrors(['deletionSequence' => 'comment.error.thread_required']);
        }
        $commentId = (string) $comment->id;
        $documentId = (string) $comment->version->document->id;

        $replyIds = $this->em->wrapInTransaction(function () use ($command, $comment): array|DomainErrors|null {
            if (!$this->comments->lockAndRefreshState($comment)) {
                return null;
            }
            if ($command->deletionSequence < 1 || $command->deletionSequence !== $comment->deletionSequence) {
                return new DomainErrors(['deletionSequence' => 'comment.error.stale_deletion']);
            }
            if (null === $comment->deletedAt) {
                return new DomainErrors(['deletionSequence' => 'comment.error.not_deleted']);
            }

            $replyIds = [];
            foreach ($this->comments->findRepliesIncludingDeleted($comment) as $reply) {
                $replyIds[] = (string) $reply->id;
                $this->em->remove($reply);
            }
            $this->em->remove($comment);

            return $replyIds;
        });
        if ($replyIds instanceof DomainErrors) {
            throw $replyIds;
        }
        if (null === $replyIds) {
            return;
        }

        $this->auditor->record(
            'review.comment_purged',
            AuditOutcome::Success,
            [
                'commentId' => $commentId,
                'documentId' => $documentId,
                'replyCount' => count($replyIds),
                'deletionSequence' => $command->deletionSequence,
            ],
            new AuditSubject('comment', $commentId),
        );
        foreach ($replyIds as $replyId) {
            $this->auditor->record(
                'review.comment_purged',
                AuditOutcome::Success,
                ['commentId' => $replyId, 'documentId' => $documentId, 'parentCommentId' => $commentId],
                new AuditSubject('comment', $replyId),
            );
        }
    }
}
