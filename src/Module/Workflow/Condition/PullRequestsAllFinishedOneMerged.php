<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Fact\PullRequestFacts;
use App\Module\Workflow\Fact\PullRequestState;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class PullRequestsAllFinishedOneMerged implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.all_finished_one_merged';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::PullRequests];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $pullRequests = $facts->pullRequests;

        return array_any($pullRequests, static fn (PullRequestFacts $pullRequest): bool => PullRequestState::Merged === $pullRequest->state)
            && array_all($pullRequests, static fn (PullRequestFacts $pullRequest): bool => PullRequestState::Open !== $pullRequest->state);
    }

    #[\Override]
    public function waitingFor(array $params): TranslatableMessage
    {
        return new TranslatableMessage('workflow.waiting.pr_all_finished_one_merged');
    }
}
