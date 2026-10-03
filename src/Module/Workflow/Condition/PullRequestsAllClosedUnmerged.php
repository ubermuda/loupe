<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Fact\PullRequestState;
use Symfony\Component\Translation\TranslatableMessage;

/** False while a closed pull request has no close time, because the wait cannot start. */
final readonly class PullRequestsAllClosedUnmerged implements TimedCondition
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
        $dueAt = $this->dueAt($facts, $params);

        return null !== $dueAt && $dueAt <= $facts->now;
    }

    #[\Override]
    public function turnsAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        $dueAt = $this->dueAt($facts, $params);

        return null !== $dueAt && $dueAt > $facts->now ? $dueAt : null;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_all_closed_unmerged' : 'workflow.waiting.pr_all_closed_unmerged', ['%minutes%' => (string) ParameterValue::int($params, 'minutes')]);
    }

    /**
     * The time the wait after the last close ends, or null while a pull request is not closed or has no close time.
     *
     * @param array<string, mixed> $params
     */
    private function dueAt(Facts $facts, array $params): ?\DateTimeImmutable
    {
        $lastClosedAt = null;
        foreach ($facts->pullRequests as $pullRequest) {
            if (PullRequestState::Closed !== $pullRequest->state || null === $pullRequest->closedAt) {
                return null;
            }
            if (null === $lastClosedAt || $pullRequest->closedAt > $lastClosedAt) {
                $lastClosedAt = $pullRequest->closedAt;
            }
        }

        return $lastClosedAt?->modify(\sprintf('+%d minutes', ParameterValue::int($params, 'minutes')));
    }
}
