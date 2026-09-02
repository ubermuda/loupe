<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Review\Entity\Comment;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReplyToCommentHandler
{
    /**
     * `comments.body` is an unbounded TEXT column, so something has to say no.
     * Generous enough that no reviewer or agent meets it in normal use.
     */
    public const int MAX_BODY_BYTES = 65_536;

    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ReplyToCommentCommand $command): Comment
    {
        $parent = $command->parent;

        // The web form enforces this through ReplyRequest, but MCP tools call
        // the handler directly. An empty reply renders as a bare avatar and an
        // empty bubble that cannot be removed on its own — only whole threads
        // are deletable.
        if ('' === trim($command->body)) {
            throw new DomainErrors(['body' => 'comment.error.reply_empty']);
        }

        if (\strlen($command->body) > self::MAX_BODY_BYTES) {
            throw new DomainErrors(['body' => 'comment.error.reply_too_long']);
        }

        // Replies always attach to the thread root: nothing else rejects a POST
        // targeting a reply, and a nested one renders invisibly and breaks
        // DeleteCommentHandler, which removes one level only — a grandchild's
        // non-nullable parent FK would abort that delete with a 500.
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

        $this->auditor->record(
            'review.comment.replied',
            AuditOutcome::Success,
            [
                'commentId' => (string) $reply->id,
                'parentCommentId' => (string) $parent->id,
                'documentId' => (string) $parent->version->document->id,
            ],
            new AuditSubject('comment', (string) $reply->id),
        );

        return $reply;
    }
}
