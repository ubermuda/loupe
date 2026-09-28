<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\PullRequestSnapshot;

/** A review arrived, and $snapshot is the state it applies to. It fires inside the transaction that holds the row lock. */
final readonly class PullRequestReviewed
{
    public function __construct(
        public ForgePullRequest $pullRequest,
        public PullRequestSnapshot $snapshot,
    ) {
    }
}
