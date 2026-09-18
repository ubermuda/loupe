<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Pending → Addressed, and nothing else. Addressed is the agent's claim that it
 * acted; Resolved is the human agreeing the thread is finished.
 */
final readonly class MarkCommentsAddressedHandler
{
    public function __construct(
        private CommentRepository $comments,
        private DocumentVersionRepository $documentVersions,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /**
     * @return list<MarkCommentAddressedOutcome> one outcome per comment, in the
     *                                           order given; a comment named more than once shares one outcome
     */
    public function __invoke(MarkCommentsAddressedCommand $command): array
    {
        $outcomes = [];

        /** @var array<string, string> $currentVersionIds latest version id, keyed by document id */
        $currentVersionIds = [];

        /** @var array<string, array{Comment, MarkCommentAddressedOutcome}> $decided keyed by comment id */
        $decided = [];

        // One transaction for the batch: each comment is written as it is
        // decided, so without this a failure partway through would leave the
        // earlier ones addressed while the call reports an error.
        $this->em->wrapInTransaction(function () use ($command, &$currentVersionIds, &$decided, &$outcomes): void {
            $documents = [];
            foreach ($command->comments as $comment) {
                $document = $comment->version->document;
                $documents[(string) $document->id] = $document;
            }
            ksort($documents);
            foreach ($documents as $document) {
                $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);
            }
            foreach ($command->comments as $comment) {
                $commentId = (string) $comment->id;

                // A comment named twice in one batch is still one comment.
                // Deciding it twice would address it and then find it already
                // addressed, so the call would answer one question two
                // different ways and record both.
                if (!isset($decided[$commentId])) {
                    $decided[$commentId] = [$comment, $this->decide($comment, $currentVersionIds)];
                }

                $outcomes[] = $decided[$commentId][1];
            }
        });

        // Record after commit so a rolled-back batch leaves no audit events.
        foreach ($decided as [$comment, $outcome]) {
            $this->auditor->record(
                'review.comment_addressed',
                // No default arm: a per-comment outcome added later must be an
                // unhandled match here rather than take a neighbour's meaning.
                match ($outcome) {
                    MarkCommentAddressedOutcome::Addressed => AuditOutcome::Success,
                    MarkCommentAddressedOutcome::AlreadyAddressed,
                    MarkCommentAddressedOutcome::AlreadyResolved => AuditOutcome::Unchanged,
                    MarkCommentAddressedOutcome::Superseded,
                    MarkCommentAddressedOutcome::Deleted,
                    MarkCommentAddressedOutcome::IsReply => AuditOutcome::Refused,
                    // The row was gone by the time the write ran, so the
                    // operation neither moved a state nor met a policy.
                    MarkCommentAddressedOutcome::NotFound => AuditOutcome::Failed,
                },
                [
                    'commentId' => (string) $comment->id,
                    'documentId' => (string) $comment->version->document->id,
                    // The enum is not backed, so the case name is the value.
                    'result' => $outcome->name,
                ],
                new AuditSubject('comment', (string) $comment->id),
            );
        }

        return $outcomes;
    }

    /**
     * @param array<string, string> $currentVersionIds latest version id, keyed by document id
     */
    private function decide(Comment $comment, array &$currentVersionIds): MarkCommentAddressedOutcome
    {
        // Checked first, because a superseded comment is wrong in a way the
        // other reasons mask: a pre-revision id still resolves and still
        // looks pending, but flipping it moves a row nobody reads while
        // the live thread stays open.
        $documentId = (string) $comment->version->document->id;
        $currentVersionIds[$documentId] ??= (string) $this->documentVersions->findLatest($comment->version->document)->id;
        if ($currentVersionIds[$documentId] !== (string) $comment->version->id) {
            return MarkCommentAddressedOutcome::Superseded;
        }

        // Status lives on the thread root, so a reply has no status of its own
        // to move. Checked before the status branch below: threadStatus reads
        // through to the root and would make a reply in a pending thread look
        // eligible.
        if (null !== $comment->parent) {
            return MarkCommentAddressedOutcome::IsReply;
        }

        if (!$this->comments->refreshState($comment)) {
            return MarkCommentAddressedOutcome::NotFound;
        }
        if ($comment->isDeleted) {
            return MarkCommentAddressedOutcome::Deleted;
        }
        if (CommentStatus::Pending !== $comment->status) {
            // No default arm: a status added later must be an unhandled
            // match here rather than be silently reported as resolved.
            return match ($comment->status) {
                CommentStatus::Addressed => MarkCommentAddressedOutcome::AlreadyAddressed,
                CommentStatus::Resolved => MarkCommentAddressedOutcome::AlreadyResolved,
            };
        }

        if (!$this->comments->markAddressedIfPending($comment)) {
            if ($this->comments->refreshState($comment) && $comment->isDeleted) {
                return MarkCommentAddressedOutcome::Deleted;
            }

            return match ($this->comments->currentStatus($comment)) {
                CommentStatus::Addressed => MarkCommentAddressedOutcome::AlreadyAddressed,
                CommentStatus::Resolved => MarkCommentAddressedOutcome::AlreadyResolved,
                default => MarkCommentAddressedOutcome::NotFound,
            };
        }

        return MarkCommentAddressedOutcome::Addressed;
    }
}
