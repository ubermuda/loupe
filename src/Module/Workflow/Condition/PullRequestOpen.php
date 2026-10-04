<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Fact\PullRequestState;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class PullRequestOpen implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.open';
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
        return PullRequestState::Open === $facts->pullRequest?->state;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_open' : 'workflow.waiting.pr_open');
    }
}
