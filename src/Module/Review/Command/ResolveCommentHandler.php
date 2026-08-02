<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

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
        // Status is a thread-level property held by the root, so resolving any
        // comment in a thread writes the root's status and nothing else.
        $root = $command->comment->parent ?? $command->comment;
        $root->status = CommentStatus::Resolved;
        $this->em->flush();
    }
}
