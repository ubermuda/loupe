<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\PullRequestSnapshot;

/** One read of a pull request, as the state before it and the state it found. */
final readonly class MoveCardsOnPullRequestStateCommand
{
    public function __construct(
        public ForgePullRequest $pullRequest,
        public PullRequestSnapshot $previous,
        public PullRequestSnapshot $current,
    ) {
    }
}
