<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DeleteCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private CommentRepository $comments,
    ) {
    }

    public function __invoke(DeleteCommentCommand $command): void
    {
        // Deleting a thread removes its replies too — they reference the root via
        // a non-nullable parent FK, so they would otherwise dangle.
        foreach ($this->comments->findReplies($command->comment) as $reply) {
            $this->em->remove($reply);
        }
        $this->em->remove($command->comment);
        $this->em->flush();
    }
}
