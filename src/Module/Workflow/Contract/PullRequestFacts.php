<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

final readonly class PullRequestFacts
{
    /**
     * @param bool  $baseIsMergeTarget the base is the default branch or the branch of the card's epic
     * @param bool  $baseIsEpicBranch  the base is the branch of the card's epic
     * @param ?Uuid $id                the id of the Forge pull request row
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
        public ?Uuid $id = null,
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

    /**
     * The part of the pull request whose change counts as a change. The close time stays out: a closed pull request
     * takes it from its last read, so a re-read changes it.
     *
     * @return list<mixed>
     */
    public function fingerprint(): array
    {
        return [
            $this->state->value,
            $this->draft,
            $this->checks->value,
            $this->conflicting,
            $this->behind,
            $this->approvalsCoveringHead,
            $this->changesRequested,
            $this->baseIsMergeTarget,
            $this->baseIsEpicBranch,
            $this->stacked,
            $this->parentMerged,
        ];
    }
}
