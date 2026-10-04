<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\FactKey;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class PullRequestChangesRequested implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.changes_requested';
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
        return true === $facts->pullRequest?->changesRequested;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_changes_requested' : 'workflow.waiting.pr_changes_requested');
    }
}
