<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** An open pull request of the card has an approval that misses its head, and no notice tells it yet. */
final readonly class ApprovalStale implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.pr.approval_stale';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [StaleApprovalFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return [] !== $facts->get(StaleApprovalFacts::class)->heads;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_pr_approval_stale' : 'workflow.waiting.card_pr_approval_stale');
    }
}
