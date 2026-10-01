<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

final readonly class PullRequestSyncView
{
    /**
     * @param ?string $reason        the cause of a failed sync
     * @param ?int    $blockerNumber the pull request whose turn comes first
     */
    public function __construct(
        public PullRequestSyncStatus $status,
        public ?string $reason = null,
        public ?int $blockerNumber = null,
    ) {
    }
}
