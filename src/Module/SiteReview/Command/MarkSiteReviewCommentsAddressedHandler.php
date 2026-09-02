<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pending → Addressed, and nothing else. Resolved is reserved for the human in
 * the web UI, and no code path here writes it.
 */
final readonly class MarkSiteReviewCommentsAddressedHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /**
     * @return list<MarkSiteReviewCommentAddressedOutcome> one outcome per comment, in the order given
     */
    public function __invoke(MarkSiteReviewCommentsAddressedCommand $command): array
    {
        $outcomes = [];

        /** @var array<string, array{SiteReviewComment, MarkSiteReviewCommentAddressedOutcome}> $decided keyed by comment id */
        $decided = [];

        // One transaction for the batch: each comment is written as it is
        // decided, so without this a failure partway through would leave the
        // earlier ones addressed while the call reports an error.
        $this->em->wrapInTransaction(function () use ($command, &$decided, &$outcomes): void {
            foreach ($command->comments as $comment) {
                $outcome = $this->decide($comment);
                $outcomes[] = $outcome;

                // One comment named twice is still one comment, so the record
                // keeps the decision that moved it and drops the echo.
                $decided[(string) $comment->id] ??= [$comment, $outcome];
            }
        });

        // One record per comment, not one per call. The batch decides four
        // different things and a single summary record carries none of them.
        // Written after the transaction commits, so a rolled-back batch
        // records nothing at all.
        foreach ($decided as [$comment, $outcome]) {
            $this->auditor->record(
                'site_review.comment.addressed',
                MarkSiteReviewCommentAddressedOutcome::Addressed === $outcome ? AuditOutcome::Success : AuditOutcome::Refused,
                [
                    'projectId' => (string) $comment->project->id,
                    'commentId' => (string) $comment->id,
                    // The enum is not backed, so the case name is the value.
                    'result' => $outcome->name,
                ],
                new AuditSubject('site_review_comment', (string) $comment->id),
            );
        }

        return $outcomes;
    }

    private function decide(SiteReviewComment $comment): MarkSiteReviewCommentAddressedOutcome
    {
        if (SiteReviewCommentStatus::Pending !== $comment->status) {
            return match ($comment->status) {
                SiteReviewCommentStatus::Addressed => MarkSiteReviewCommentAddressedOutcome::AlreadyAddressed,
                default => MarkSiteReviewCommentAddressedOutcome::AlreadyResolved,
            };
        }

        // The status check above is advisory: it produces the precise outcome,
        // but a human can click Resolve between it and the write. Only the
        // conditional UPDATE decides.
        if (!$this->siteReviewComments->markAddressedIfPending($comment)) {
            return match ($this->siteReviewComments->currentStatus($comment)) {
                SiteReviewCommentStatus::Addressed => MarkSiteReviewCommentAddressedOutcome::AlreadyAddressed,
                SiteReviewCommentStatus::Resolved => MarkSiteReviewCommentAddressedOutcome::AlreadyResolved,
                default => MarkSiteReviewCommentAddressedOutcome::NotFound,
            };
        }

        return MarkSiteReviewCommentAddressedOutcome::Addressed;
    }
}
