<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class DeleteCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private CommentRepository $comments,
        private DocumentVersionRepository $documentVersions,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(DeleteCommentCommand $command): void
    {
        if (null !== $command->comment->parent) {
            throw new DomainErrors(['comment' => 'comment.error.thread_required']);
        }
        $commentId = (string) $command->comment->id;
        $documentId = (string) $command->comment->version->document->id;

        $replyIds = $this->em->wrapInTransaction(function () use ($command): array|DomainErrors|null {
            $comment = $command->comment;
            if (!$this->comments->lockAndRefreshState($comment)) {
                return new DomainErrors(['comment' => 'comment.error.not_found']);
            }
            if ($comment->isDeleted) {
                return null;
            }
            if ($this->documentVersions->findLatest($comment->version->document)->id != $comment->version->id) {
                return new DomainErrors(['comment' => 'review.document.comment.error.stale_version']);
            }
            $replyIds = [];
            foreach ($this->comments->findRepliesIncludingDeleted($comment) as $reply) {
                $replyIds[] = (string) $reply->id;
            }
            $comment->deletedAt = new \DateTimeImmutable();
            ++$comment->deletionSequence;

            return $replyIds;
        });
        if ($replyIds instanceof DomainErrors) {
            throw $replyIds;
        }
        if (null === $replyIds) {
            return;
        }

        $this->auditor->record(
            'review.comment_deleted',
            AuditOutcome::Success,
            [
                'commentId' => $commentId,
                'documentId' => $documentId,
                'replyCount' => \count($replyIds),
            ],
            new AuditSubject('comment', $commentId),
        );

        foreach ($replyIds as $replyId) {
            $this->auditor->record(
                'review.comment_deleted',
                AuditOutcome::Success,
                [
                    'commentId' => $replyId,
                    'documentId' => $documentId,
                    'parentCommentId' => $commentId,
                ],
                new AuditSubject('comment', $replyId),
            );
        }
    }
}
