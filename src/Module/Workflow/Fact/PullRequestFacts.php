<?php

declare(strict_types=1);

namespace App\Module\Workflow\Fact;

final readonly class PullRequestFacts
{
    public function __construct(
        public PullRequestState $state,
        public bool $draft,
        public ChecksState $checks,
        public bool $conflicting,
        public bool $behind,
        public int $approvalsCoveringHead,
        public bool $changesRequested,
        public bool $baseIsMergeTarget,
        public bool $stacked,
        public bool $parentMerged,
        public ?\DateTimeImmutable $closedAt,
    ) {
    }
}
