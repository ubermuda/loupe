<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Review\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DeleteCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private CommentRepository $comments,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(DeleteCommentCommand $command): void
    {
        // Read before the flush, here and for each reply below: Doctrine nulls a
        // removed entity's identifier, so every id is gone by the time the
        // records are written.
        $commentId = (string) $command->comment->id;
        $documentId = (string) $command->comment->version->document->id;

        // Deleting a thread removes its replies too — they reference the root via
        // a non-nullable parent FK, so they would otherwise dangle.
        $replyIds = [];
        foreach ($this->comments->findReplies($command->comment) as $reply) {
            $replyIds[] = (string) $reply->id;
            $this->em->remove($reply);
        }
        $this->em->remove($command->comment);
        $this->em->flush();

        // One record per deleted comment: a reply is a row of its own, written
        // by its own author, and a single record for the thread leaves no trace
        // that those ids existed.
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
