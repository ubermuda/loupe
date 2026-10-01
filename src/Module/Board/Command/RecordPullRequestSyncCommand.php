<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Forge\Entity\ForgePullRequest;

/** A read found the head that a sync by Loupe produced. */
final readonly class RecordPullRequestSyncCommand
{
    public function __construct(
        public ForgePullRequest $pullRequest,
    ) {
    }
}
