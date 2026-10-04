<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\PullRequestSnapshot;

/** One read of a pull request. A read that only carries a review passes one snapshot as both $previous and $current. */
final readonly class WritePullRequestFactEventsCommand
{
    public function __construct(
        public ForgePullRequest $pullRequest,
        public PullRequestSnapshot $previous,
        public PullRequestSnapshot $current,
        public ?PullRequestReview $reviewVerdict = null,
    ) {
    }
}
