<?php

declare(strict_types=1);

namespace App\Tests\Module\Board;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestBranchUpdater;

final class FakePullRequestBranchUpdater implements PullRequestBranchUpdater
{
    /** @var list<array{ForgePullRequest, string}> the pull request and the expected head of each update */
    public array $updates = [];

    public ?\Throwable $failure = null;

    /** @var ?\Closure(ForgePullRequest): void runs before the failure, as a forge read that lands during the call */
    public ?\Closure $during = null;

    #[\Override]
    public function supports(string $forge): bool
    {
        return 'github' === $forge;
    }

    #[\Override]
    public function update(ForgePullRequest $pullRequest, string $expectedHeadSha): void
    {
        $this->updates[] = [$pullRequest, $expectedHeadSha];
        if (null !== $this->during) {
            ($this->during)($pullRequest);
        }
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
