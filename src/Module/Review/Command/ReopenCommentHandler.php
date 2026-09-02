<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Review\Entity\CommentStatus;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReopenCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ReopenCommentCommand $command): void
    {
        $comment = $command->comment;

        // Status is a thread-level property held by the root, so a reply is not a
        // reopenable target — the mirror of the guard in ResolveCommentHandler, and
        // for the same reason: nothing but the template withholds the button.
        if (null !== $comment->parent) {
            throw new DomainErrors(['comment' => 'comment.error.reopen_reply']);
        }

        if (CommentStatus::Resolved !== $comment->status) {
            throw new DomainErrors(['comment' => 'comment.error.reopen_not_resolved']);
        }

        $comment->status = CommentStatus::Pending;
        $this->em->flush();

        $this->auditor->record(
            'review.comment.reopened',
            AuditOutcome::Success,
            [
                'commentId' => (string) $comment->id,
                'documentId' => (string) $comment->version->document->id,
            ],
            new AuditSubject('comment', (string) $comment->id),
        );
    }
}
