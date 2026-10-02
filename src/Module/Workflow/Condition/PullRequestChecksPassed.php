<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\ChecksState;
use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class PullRequestChecksPassed implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.checks_passed';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::PullRequest];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return ChecksState::Passed === $facts->pullRequest?->checks;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_checks_passed' : 'workflow.waiting.pr_checks_passed');
    }
}
