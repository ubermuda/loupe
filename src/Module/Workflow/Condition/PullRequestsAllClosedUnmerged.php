<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Fact\PullRequestState;
use Symfony\Component\Translation\TranslatableMessage;

/** False while a closed pull request has no close time, because the wait cannot start. */
final readonly class PullRequestsAllClosedUnmerged implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.all_closed_unmerged';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('minutes', ParameterType::Int)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::PullRequests];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $lastClosedAt = null;
        foreach ($facts->pullRequests as $pullRequest) {
            if (PullRequestState::Closed !== $pullRequest->state || null === $pullRequest->closedAt) {
                return false;
            }
            if (null === $lastClosedAt || $pullRequest->closedAt > $lastClosedAt) {
                $lastClosedAt = $pullRequest->closedAt;
            }
        }

        if (null === $lastClosedAt) {
            return false;
        }

        $minutes = ParameterValue::int($params, 'minutes');

        return $lastClosedAt->modify(\sprintf('+%d minutes', $minutes)) <= $facts->now;
    }

    #[\Override]
    public function waitingFor(array $params): TranslatableMessage
    {
        return new TranslatableMessage('workflow.waiting.pr_all_closed_unmerged', ['%minutes%' => (string) ParameterValue::int($params, 'minutes')]);
    }
}
