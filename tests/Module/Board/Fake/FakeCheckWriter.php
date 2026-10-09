<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Fake;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestCheckConclusion;
use App\Module\Forge\Service\PullRequestCheckFailed;
use App\Module\Forge\Service\PullRequestCheckWriter;

/** Records each check reported on a `github` pull request. A run gets the id 100 plus the count of runs made so far. */
final class FakeCheckWriter implements PullRequestCheckWriter
{
    /** @var list<array{number: int, name: string, sha: string, conclusion: PullRequestCheckConclusion, title: string, summary: string, runId: ?int}> */
    public array $published = [];

    /** @var list<int> the numbers whose check fails */
    public array $failingNumbers = [];

    /** Whether a failing check fails for good, or a retry can fix it. */
    public bool $failsForGood = true;

    private int $runs = 0;

    #[\Override]
    public function supports(string $forge): bool
    {
        return 'github' === $forge;
    }

    #[\Override]
    public function publish(ForgePullRequest $pullRequest, string $name, string $sha, PullRequestCheckConclusion $conclusion, string $title, string $summary, ?int $runId): int
    {
        $this->published[] = ['number' => $pullRequest->number, 'name' => $name, 'sha' => $sha, 'conclusion' => $conclusion, 'title' => $title, 'summary' => $summary, 'runId' => $runId];
        if (\in_array($pullRequest->number, $this->failingNumbers, true)) {
            throw new PullRequestCheckFailed($this->failsForGood ? 'permission' : 'server_error', $this->failsForGood);
        }

        return $runId ?? 100 + ++$this->runs;
    }
}
