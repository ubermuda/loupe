<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Repository\UserRepository;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Repository\DocumentVersionRepository;

/**
 * The agent door onto ReplyToCommentHandler.
 *
 * It owns the two rules that belong to that door alone: the author is the agent
 * user rather than the token holder, and a comment from a superseded version is
 * refused. The web door has neither, so both stay out of the shared handler.
 */
final readonly class ReplyToCommentAsAgentHandler
{
    public function __construct(
        private ReplyToCommentHandler $replyToComment,
        private UserRepository $users,
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    public function __invoke(ReplyToCommentAsAgentCommand $command): Comment
    {
        $parent = $command->parent;

        // There is no id to forward the reply to either: the old-to-new comment
        // mapping a revision builds is in memory and never stored.
        $currentVersion = $this->documentVersions->findLatest($parent->version->document);
        if ($currentVersion->id !== $parent->version->id) {
            throw new StaleCommentVersion($parent->version->versionNumber, $currentVersion->versionNumber);
        }

        return ($this->replyToComment)(new ReplyToCommentCommand(
            actor: $this->users->agent(),
            parent: $parent,
            body: $command->body,
        ));
    }
}
