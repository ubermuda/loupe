<?php

declare(strict_types=1);

namespace App\Module\Project\Stats;

/**
 * The per-project figures the projects list renders. Each provider fills only
 * what its own module owns and leaves the rest at zero, so the totals are
 * additive and Project never needs to know which module supplied which number.
 */
final readonly class ProjectStats
{
    public function __construct(
        public int $documentCount = 0,
        public int $commentCount = 0,
        public int $openCommentCount = 0,
    ) {
    }

    public function merge(self $other): self
    {
        return new self(
            $this->documentCount + $other->documentCount,
            $this->commentCount + $other->commentCount,
            $this->openCommentCount + $other->openCommentCount,
        );
    }
}
