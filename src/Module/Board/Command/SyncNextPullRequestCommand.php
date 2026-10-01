<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use Symfony\Component\Uid\Uuid;

final readonly class SyncNextPullRequestCommand
{
    public function __construct(
        public Uuid $projectId,
        /** The pull request whose update failed for a while, and the head Loupe asked to update. */
        public ?Uuid $retryPullRequestId = null,
        public ?string $retrySha = null,
        public int $attempt = 0,
    ) {
    }
}
