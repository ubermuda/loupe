<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\CommentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class ResolveCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ResolveCommentCommand $command): void
    {
        // Status is a thread-level property held by the root, so a reply is not a
        // resolvable target. Nothing else rejects a POST aimed at one: the voter
        // checks only document ownership, and the CSRF token is a single static id
        // rendered on every thread, so a document owner holds a valid token for any
        // comment. Only the template withholds the button.
        if (null !== $command->comment->parent) {
            throw new DomainErrors(['comment' => 'comment.error.resolve_reply']);
        }

        // Read before the assignment below overwrites it.
        $alreadyResolved = CommentStatus::Resolved === $command->comment->status;

        $command->comment->status = CommentStatus::Resolved;
        $this->em->flush();

        $this->auditor->record(
            'review.comment_resolved',
            // Unchanged when the thread was already resolved: no policy refused
            // the actor, and the UI reports the click as a success.
            $alreadyResolved ? AuditOutcome::Unchanged : AuditOutcome::Success,
            [
                'commentId' => (string) $command->comment->id,
                'documentId' => (string) $command->comment->version->document->id,
            ],
            new AuditSubject('comment', (string) $command->comment->id),
        );
    }
}
