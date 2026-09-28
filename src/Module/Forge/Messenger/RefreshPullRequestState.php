<?php

declare(strict_types=1);

namespace App\Module\Forge\Messenger;

/** A row read at or after $requestedAt already answers this message. */
final readonly class RefreshPullRequestState
{
    public function __construct(
        public string $pullRequestId,
        public \DateTimeImmutable $requestedAt,
    ) {
    }
}
