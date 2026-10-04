<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\FactKey;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** True for a card with no child, so that a rule for any card can require it. */
final readonly class CardChildrenFinished implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.children_finished';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::Children];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return 0 === $facts->card->openChildCount;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_children_finished' : 'workflow.waiting.card_children_finished');
    }
}
