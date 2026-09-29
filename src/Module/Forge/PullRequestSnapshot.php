<?php

declare(strict_types=1);

namespace App\Module\Forge;

use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;

/** What a forge said about one pull request at one read. The defaults are those of a row nobody has read yet. */
final readonly class PullRequestSnapshot
{
    /** @param list<string> $failedChecks the names of the failed checks on $checksSha */
    public function __construct(
        public PullRequestState $state = PullRequestState::Open,
        public bool $draft = false,
        public ?string $headSha = null,
        public ?string $baseBranch = null,
        public PullRequestChecks $checks = PullRequestChecks::Pending,
        public ?string $checksSha = null,
        public array $failedChecks = [],
        public PullRequestMergeability $mergeability = PullRequestMergeability::Unknown,
        public PullRequestReview $review = PullRequestReview::None,
        public bool $readyToMerge = false,
        public ?string $changesRequestedSha = null,
    ) {
    }

    /** Whether this read concluded the checks with a new result, or on a new commit. */
    public function checksConcludedSince(self $previous): bool
    {
        return (PullRequestChecks::Passed === $this->checks || PullRequestChecks::Failed === $this->checks)
            && ($previous->checks !== $this->checks || $previous->checksSha !== $this->checksSha);
    }

    /** Strict on every field, because a loose `==` holds two numeric-looking commit hashes equal. */
    public function equals(self $other): bool
    {
        return $this->state === $other->state
            && $this->draft === $other->draft
            && $this->headSha === $other->headSha
            && $this->baseBranch === $other->baseBranch
            && $this->checks === $other->checks
            && $this->checksSha === $other->checksSha
            && $this->failedChecks === $other->failedChecks
            && $this->mergeability === $other->mergeability
            && $this->review === $other->review
            && $this->readyToMerge === $other->readyToMerge
            && $this->changesRequestedSha === $other->changesRequestedSha;
    }
}
