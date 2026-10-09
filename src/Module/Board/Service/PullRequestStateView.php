<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestState;

/** The stored state of one pull request that a card links. */
final readonly class PullRequestStateView
{
    /** @param list<string> $failedChecks */
    public function __construct(
        public PullRequestState $state,
        public bool $draft,
        public PullRequestChecks $checks,
        public array $failedChecks,
        public PullRequestMergeability $mergeability,
        public PullRequestReviewView $review,
        public bool $readyToMerge,
        public ?\DateTimeImmutable $refreshedAt,
    ) {
    }

    public static function of(ForgePullRequest $row): self
    {
        return new self(
            $row->state,
            $row->draft,
            $row->checks,
            $row->failedChecks,
            $row->mergeability,
            PullRequestReviewView::of($row),
            $row->readyToMerge,
            $row->refreshedAt,
        );
    }
}
