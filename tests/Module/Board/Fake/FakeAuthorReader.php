<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Fake;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Service\PullRequestStateReader;
use App\Module\Forge\Service\PullRequestUnreadable;

/** Answers every GitHub read with one snapshot, or fails it, and counts the reads. */
final class FakeAuthorReader implements PullRequestStateReader
{
    public int $reads = 0;

    public ?PullRequestUnreadable $failure = null;

    public function __construct(
        public PullRequestSnapshot $snapshot = new PullRequestSnapshot(),
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return 'github' === $forge;
    }

    #[\Override]
    public function read(ForgePullRequest $pullRequest): PullRequestSnapshot
    {
        ++$this->reads;
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->snapshot;
    }
}
