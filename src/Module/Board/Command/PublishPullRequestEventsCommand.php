<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\PullRequestSnapshot;

/** A review passes one snapshot as both $previous and $current, so only its own facts follow. */
final readonly class PublishPullRequestEventsCommand
{
    public function __construct(
        public ForgePullRequest $pullRequest,
        public PullRequestSnapshot $previous,
        public PullRequestSnapshot $current,
        public bool $reviewed = false,
    ) {
    }
}
