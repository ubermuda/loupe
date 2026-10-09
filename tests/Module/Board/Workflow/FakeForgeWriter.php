<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestBaseChanger;
use App\Module\Forge\Service\PullRequestBranchUpdater;
use App\Module\Forge\Service\PullRequestMerger;
use App\Module\Forge\Service\PullRequestOpener;
use App\Module\Forge\Service\PullRequestStateWriter;

/** Records each write to a `github` pull request, and throws the failure it holds. */
final class FakeForgeWriter implements PullRequestMerger, PullRequestBaseChanger, PullRequestBranchUpdater, PullRequestStateWriter, PullRequestOpener
{
    /** @var list<list<int|string|bool>> the call, the pull request number, then the arguments */
    public array $calls = [];

    public ?\Throwable $failure = null;

    /** @var list<int> the numbers that fail, and every number when empty */
    public array $failingNumbers = [];

    public int $openedNumber = 900;

    #[\Override]
    public function supports(string $forge): bool
    {
        return 'github' === $forge;
    }

    #[\Override]
    public function merge(ForgePullRequest $pullRequest, string $method, string $expectedHeadSha): void
    {
        $this->record(['merge', $pullRequest->number, $method, $expectedHeadSha]);
    }

    #[\Override]
    public function changeBase(ForgePullRequest $pullRequest, string $base): void
    {
        $this->record(['changeBase', $pullRequest->number, $base]);
    }

    #[\Override]
    public function update(ForgePullRequest $pullRequest, string $expectedHeadSha): void
    {
        $this->record(['update', $pullRequest->number, $expectedHeadSha]);
    }

    #[\Override]
    public function setDraft(ForgePullRequest $pullRequest, bool $draft): void
    {
        $this->record(['setDraft', $pullRequest->number, $draft]);
    }

    #[\Override]
    public function close(ForgePullRequest $pullRequest): void
    {
        $this->record(['close', $pullRequest->number]);
    }

    #[\Override]
    public function open(ForgePullRequest $from, string $head, string $base, string $title, string $body): int
    {
        $this->record(['open', $from->number, $head, $base, $title, $body]);

        return $this->openedNumber;
    }

    #[\Override]
    public function url(ForgePullRequest $from, int $number): string
    {
        return 'https://github.com/'.$from->repository.'/pull/'.$number;
    }

    /** @param list<int|string|bool> $call */
    private function record(array $call): void
    {
        $this->calls[] = $call;
        if (null !== $this->failure && ([] === $this->failingNumbers || \in_array($call[1], $this->failingNumbers, true))) {
            throw $this->failure;
        }
    }
}
