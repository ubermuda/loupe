<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestBranchUpdater;
use App\Module\Forge\Service\PullRequestSyncFailed;

/** Records each branch update for the `fake` forge, and throws the failure it holds. */
final class FakePullRequestBranchUpdater implements PullRequestBranchUpdater
{
    /** @var list<array{ForgePullRequest, string}> the pull request and the expected head of each update */
    public array $updates = [];

    /** Runs inside each update, such as a read that moves the head while the forge answers. */
    public ?\Closure $duringUpdate = null;

    public ?PullRequestSyncFailed $failure = null;

    #[\Override]
    public function supports(string $forge): bool
    {
        return FakePullRequestStateReader::FORGE === $forge;
    }

    #[\Override]
    public function update(ForgePullRequest $pullRequest, string $expectedHeadSha): void
    {
        $this->updates[] = [$pullRequest, $expectedHeadSha];
        if (null !== $this->duringUpdate) {
            ($this->duringUpdate)();
        }
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
