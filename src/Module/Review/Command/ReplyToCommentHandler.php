<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReplyToCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ReplyToCommentCommand $command): Comment
    {
        $parent = $command->parent;

        $reply = new Comment(
            version: $parent->version,
            author: $command->actor,
            body: $command->body,
            anchor: $parent->anchor,
            parent: $parent,
        );

        $this->em->persist($reply);
        $this->em->flush();

        return $reply;
    }
}
