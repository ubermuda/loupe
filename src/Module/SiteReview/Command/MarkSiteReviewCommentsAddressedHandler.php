<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

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
    ) {
    }

    /**
     * @return list<MarkSiteReviewCommentAddressedOutcome> one outcome per comment, in the order given
     */
    public function __invoke(MarkSiteReviewCommentsAddressedCommand $command): array
    {
        $outcomes = [];

        // One transaction for the batch: each comment is written as it is
        // decided, so without this a failure partway through would leave the
        // earlier ones addressed while the call reports an error.
        $this->em->wrapInTransaction(function () use ($command, &$outcomes): void {
            foreach ($command->comments as $comment) {
                if (SiteReviewCommentStatus::Pending !== $comment->status) {
                    $outcomes[] = match ($comment->status) {
                        SiteReviewCommentStatus::Addressed => MarkSiteReviewCommentAddressedOutcome::AlreadyAddressed,
                        default => MarkSiteReviewCommentAddressedOutcome::AlreadyResolved,
                    };
                    continue;
                }

                // The status check above is advisory: it produces the precise
                // outcome, but a human can click Resolve between it and the
                // write. Only the conditional UPDATE decides.
                if (!$this->siteReviewComments->markAddressedIfPending($comment)) {
                    $outcomes[] = match ($this->siteReviewComments->currentStatus($comment)) {
                        SiteReviewCommentStatus::Addressed => MarkSiteReviewCommentAddressedOutcome::AlreadyAddressed,
                        SiteReviewCommentStatus::Resolved => MarkSiteReviewCommentAddressedOutcome::AlreadyResolved,
                        default => MarkSiteReviewCommentAddressedOutcome::NotFound,
                    };
                    continue;
                }

                $outcomes[] = MarkSiteReviewCommentAddressedOutcome::Addressed;
            }
        });

        return $outcomes;
    }
}
