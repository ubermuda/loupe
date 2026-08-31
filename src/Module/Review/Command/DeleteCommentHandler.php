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
        // Read before the flush: Doctrine nulls a removed entity's identifier,
        // so both ids are gone by the time the record is written.
        $commentId = (string) $command->comment->id;
        $documentId = (string) $command->comment->version->document->id;

        // Deleting a thread removes its replies too — they reference the root via
        // a non-nullable parent FK, so they would otherwise dangle.
        $replyCount = 0;
        foreach ($this->comments->findReplies($command->comment) as $reply) {
            $this->em->remove($reply);
            ++$replyCount;
        }
        $this->em->remove($command->comment);
        $this->em->flush();

        $this->auditor->record(
            'review.comment.deleted',
            AuditOutcome::Success,
            [
                'commentId' => $commentId,
                'documentId' => $documentId,
                'replyCount' => $replyCount,
            ],
            new AuditSubject('comment', $commentId),
        );
    }
}
