<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

final readonly class PullRequestFacts
{
    /**
     * @param bool $baseIsMergeTarget the base is the default branch or the branch of the card's epic
     * @param bool $baseIsEpicBranch  the base is the branch of the card's epic
     */
    public function __construct(
        public PullRequestState $state,
        public bool $draft,
        public ChecksState $checks,
        public bool $conflicting,
        public bool $behind,
        public int $approvalsCoveringHead,
        public bool $changesRequested,
        public bool $baseIsMergeTarget,
        public bool $baseIsEpicBranch,
        public bool $stacked,
        public bool $parentMerged,
        public ?\DateTimeImmutable $closedAt,
    ) {
    }

    /** What a fix of the pull request answers, most pressing first. Null when nothing needs a fix. */
    public function fixReason(): ?string
    {
        return match (true) {
            $this->conflicting => 'conflict',
            ChecksState::Failed === $this->checks => 'checks-failed',
            $this->changesRequested => 'changes-requested',
            default => null,
        };
    }
}
