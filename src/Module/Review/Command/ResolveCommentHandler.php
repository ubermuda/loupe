<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\CommentStatus;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ResolveCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
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

        $command->comment->status = CommentStatus::Resolved;
        $this->em->flush();
    }
}
