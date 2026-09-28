<?php

declare(strict_types=1);

namespace App\Module\Forge\Messenger;

use App\Module\Forge\Entity\PullRequestReview;

/**
 * A row read at or after $requestedAt already answers this message. The verdict
 * says a review arrived, so the read announces it even when it is skipped.
 */
final readonly class RefreshPullRequestState
{
    public function __construct(
        public string $pullRequestId,
        public \DateTimeImmutable $requestedAt,
        public ?PullRequestReview $verdict = null,
    ) {
    }

    /**
     * Unserialize skips the constructor. An older message has no verdict, or a
     * bool marker that names no verdict, so both read as a plain refresh.
     *
     * @param array{pullRequestId: string, requestedAt: \DateTimeImmutable, verdict?: ?PullRequestReview, reviewSubmitted?: bool} $data
     */
    public function __unserialize(array $data): void
    {
        $this->pullRequestId = $data['pullRequestId'];
        $this->requestedAt = $data['requestedAt'];
        $this->verdict = $data['verdict'] ?? null;
    }
}
