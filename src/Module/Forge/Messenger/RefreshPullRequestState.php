<?php

declare(strict_types=1);

namespace App\Module\Forge\Messenger;

/**
 * A row read at or after $requestedAt already answers this message. The marker
 * says a review arrived, so the read announces it even when it is skipped.
 */
final readonly class RefreshPullRequestState
{
    public function __construct(
        public string $pullRequestId,
        public \DateTimeImmutable $requestedAt,
        public bool $reviewSubmitted = false,
    ) {
    }

    /**
     * Unserialize skips the constructor, so a message queued before the marker
     * existed would leave it uninitialised.
     *
     * @param array{pullRequestId: string, requestedAt: \DateTimeImmutable, reviewSubmitted?: bool} $data
     */
    public function __unserialize(array $data): void
    {
        $this->pullRequestId = $data['pullRequestId'];
        $this->requestedAt = $data['requestedAt'];
        $this->reviewSubmitted = $data['reviewSubmitted'] ?? false;
    }
}
