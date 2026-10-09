<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestList;
use App\Module\Workflow\Contract\PullRequestState;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class PullRequestsAllFinishedOneMerged implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.all_finished_one_merged';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.forge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [PullRequestList::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $pullRequests = $facts->pullRequests();

        return array_any($pullRequests, static fn (PullRequestFacts $pullRequest): bool => PullRequestState::Merged === $pullRequest->state)
            && array_all($pullRequests, static fn (PullRequestFacts $pullRequest): bool => PullRequestState::Open !== $pullRequest->state);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_all_finished_one_merged' : 'workflow.waiting.pr_all_finished_one_merged');
    }
}
