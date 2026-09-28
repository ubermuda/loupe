<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\PullRequestSnapshot;

/**
 * A review with $verdict arrived, and $snapshot is the stored state it applies
 * to. It fires inside the transaction that holds the row lock.
 */
final readonly class PullRequestReviewed
{
    public function __construct(
        public ForgePullRequest $pullRequest,
        public PullRequestReview $verdict,
        public PullRequestSnapshot $snapshot,
    ) {
    }
}
