<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use Doctrine\ORM\EntityManagerInterface;

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
     * @return list<MarkCommentAddressedOutcome> one outcome per comment, in the order given
     */
    public function __invoke(MarkCommentsAddressedCommand $command): array
    {
        $outcomes = [];

        /** @var array<string, string> $currentVersionIds latest version id, keyed by document id */
        $currentVersionIds = [];

        // One transaction for the batch: each comment is written as it is
        // decided, so without this a failure partway through would leave the
        // earlier ones addressed while the call reports an error.
        $this->em->wrapInTransaction(function () use ($command, &$currentVersionIds, &$outcomes): void {
            foreach ($command->comments as $comment) {
                // Checked first, because a superseded comment is wrong in a way the
                // other reasons mask: a pre-revision id still resolves and still
                // looks pending, but flipping it moves a row nobody reads while
                // the live thread stays open.
                $documentId = (string) $comment->version->document->id;
                $currentVersionIds[$documentId] ??= (string) $this->documentVersions->findLatest($comment->version->document)->id;
                if ($currentVersionIds[$documentId] !== (string) $comment->version->id) {
                    $outcomes[] = MarkCommentAddressedOutcome::Superseded;
                    continue;
                }

                // Status lives on the thread root, so a reply has no status of
                // its own to move. Checked before the status branch below:
                // threadStatus reads through to the root and would make a reply
                // in a pending thread look eligible.
                if (null !== $comment->parent) {
                    $outcomes[] = MarkCommentAddressedOutcome::IsReply;
                    continue;
                }

                if (CommentStatus::Pending !== $comment->status) {
                    // No default arm: a status added later must be an unhandled
                    // match here rather than be silently reported as resolved.
                    $outcomes[] = match ($comment->status) {
                        CommentStatus::Addressed => MarkCommentAddressedOutcome::AlreadyAddressed,
                        CommentStatus::Resolved => MarkCommentAddressedOutcome::AlreadyResolved,
                    };
                    continue;
                }

                // The status check above is advisory: it produces the precise
                // outcome, but a human can click Resolve between it and the
                // write. Only the conditional UPDATE decides.
                if (!$this->comments->markAddressedIfPending($comment)) {
                    $outcomes[] = match ($this->comments->currentStatus($comment)) {
                        CommentStatus::Addressed => MarkCommentAddressedOutcome::AlreadyAddressed,
                        CommentStatus::Resolved => MarkCommentAddressedOutcome::AlreadyResolved,
                        default => MarkCommentAddressedOutcome::NotFound,
                    };
                    continue;
                }

                $outcomes[] = MarkCommentAddressedOutcome::Addressed;
            }
        });

        // One record per comment, not one per call. The batch produces six
        // different per-comment outcomes, which a single summary record cannot
        // carry. Written after the transaction commits, so a rolled-back batch
        // records nothing at all.
        foreach ($command->comments as $index => $comment) {
            $outcome = $outcomes[$index];

            $this->auditor->record(
                'review.comment.addressed',
                MarkCommentAddressedOutcome::Addressed === $outcome ? AuditOutcome::Success : AuditOutcome::Refused,
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
}
