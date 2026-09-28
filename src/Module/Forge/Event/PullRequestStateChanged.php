<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\PullRequestSnapshot;

/**
 * A read found a pull request in a new state, or carried a review verdict, or
 * both. A read with a verdict alone has equal snapshots. It fires inside the
 * transaction that wrote the row, once per read.
 */
final readonly class PullRequestStateChanged
{
    public function __construct(
        public ForgePullRequest $pullRequest,
        public PullRequestSnapshot $previous,
        public PullRequestSnapshot $current,
        public ?PullRequestReview $reviewVerdict = null,
    ) {
    }
}
