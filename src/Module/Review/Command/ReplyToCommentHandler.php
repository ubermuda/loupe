<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
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

        // Replies always attach to the thread root. Nothing else rejects a POST
        // targeting a reply (the voter checks only document ownership), and a
        // nested reply is invisible in thread rendering (CommentThread.html.twig
        // only ever renders one level) plus breaks DeleteCommentHandler, which
        // removes only one level of replies — a grandchild's non-nullable
        // parent FK would abort that delete with a 500.
        if (null !== $parent->parent) {
            throw new DomainErrors(['body' => 'comment.error.reply_to_reply']);
        }

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
