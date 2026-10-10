<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Service\ForgePullRequestWrites;

/** The stored state of one pull request that a card links. */
final readonly class PullRequestStateView
{
    /**
     * @param list<string>         $failedChecks
     * @param ?PullRequestSyncView $sync         null when the project does not sync a behind pull request, or the row has nothing to show
     */
    public function __construct(
        public PullRequestState $state,
        public bool $draft,
        public PullRequestChecks $checks,
        public array $failedChecks,
        public PullRequestMergeability $mergeability,
        public PullRequestReviewView $review,
        public bool $readyToMerge,
        public ?\DateTimeImmutable $refreshedAt,
        public ?PullRequestSyncView $sync = null,
        public ?string $baseBranch = null,
        public ?string $defaultBranch = null,
        public bool $approvalCoversHead = false,
        public ?\DateTimeImmutable $readySince = null,
        public ?\DateTimeImmutable $checksFailedSince = null,
        public ?\DateTimeImmutable $conflictingSince = null,
        public ?\DateTimeImmutable $waitsForApprovalSince = null,
        /** When Loupe asked the forge to merge, sync or change the base, and the forge has not answered. Null when nothing is in flight. */
        public ?\DateTimeImmutable $forgeRequestedAt = null,
        public bool $mergeInFlight = false,
    ) {
    }

    public static function of(ForgePullRequest $row, ?PullRequestSyncView $sync = null, ?\DateTimeImmutable $now = null): self
    {
        $now ??= new \DateTimeImmutable();
        $syncCutoff = $now->modify(\sprintf('-%d seconds', SyncLine::MARKER_LIFETIME_SECONDS));
        // A merge or a base change the forge never answered stops counting when Loupe would ask for it again.
        $requestCutoff = $now->modify(\sprintf('-%d seconds', ForgePullRequestWrites::MARKER_LIFETIME_SECONDS));
        $open = PullRequestState::Open === $row->state;
        $mergeInFlight = $open && null !== $row->mergeRequestedSha && null !== $row->mergeRequestedAt && $row->mergeRequestedAt > $requestCutoff;
        $requests = array_filter([
            $mergeInFlight ? $row->mergeRequestedAt : null,
            $open && null !== $row->baseChangeRequestedTo && null !== $row->baseChangeRequestedAt && $row->baseChangeRequestedAt > $requestCutoff ? $row->baseChangeRequestedAt : null,
            $open && null !== $row->syncFromSha && null === $row->syncFailedReason && null !== $row->syncRequestedAt && $row->syncRequestedAt > $syncCutoff ? $row->syncRequestedAt : null,
        ]);

        return new self(
            $row->state,
            $row->draft,
            $row->checks,
            $row->failedChecks,
            $row->mergeability,
            PullRequestReviewView::of($row),
            $row->readyToMerge,
            $row->refreshedAt,
            $sync,
            $row->baseBranch,
            $row->defaultBranch,
            PullRequestReview::Approved === $row->review && null !== $row->approvalId && !$row->approvalIsStale(),
            $row->readySince,
            $row->checksFailedSince,
            $row->conflictingSince,
            $row->waitsForApprovalSince,
            [] === $requests ? null : min($requests),
            $mergeInFlight,
        );
    }
}
