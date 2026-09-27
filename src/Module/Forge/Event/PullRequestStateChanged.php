<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\PullRequestSnapshot;

/** A read found a pull request in a new state. It fires inside the transaction that wrote the row. */
final readonly class PullRequestStateChanged
{
    public function __construct(
        public ForgePullRequest $pullRequest,
        public PullRequestSnapshot $previous,
        public PullRequestSnapshot $current,
    ) {
    }
}
