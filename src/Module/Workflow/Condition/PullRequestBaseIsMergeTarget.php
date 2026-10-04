<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** The base is the default branch, or the branch of the card's epic. */
final readonly class PullRequestBaseIsMergeTarget implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.base_is_merge_target';
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
        return true === $facts->pullRequest?->baseIsMergeTarget;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_base_is_merge_target' : 'workflow.waiting.pr_base_is_merge_target');
    }
}
