<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** The base is the branch of the card's epic. */
final readonly class PullRequestBaseIsEpicBranch implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'pr.base_is_epic_branch';
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
        return true === $facts->pullRequest?->baseIsEpicBranch;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.pr_base_is_epic_branch' : 'workflow.waiting.pr_base_is_epic_branch');
    }
}
