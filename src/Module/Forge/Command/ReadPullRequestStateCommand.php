<?php

declare(strict_types=1);

namespace App\Module\Forge\Command;

use App\Module\Forge\Entity\PullRequestReview;

/** Reads one tracked pull request, unless a read since $requestedAt already covers it. */
final readonly class ReadPullRequestStateCommand
{
    public function __construct(
        public string $pullRequestId,
        public \DateTimeImmutable $requestedAt,
        public ?PullRequestReview $verdict = null,
    ) {
    }
}
