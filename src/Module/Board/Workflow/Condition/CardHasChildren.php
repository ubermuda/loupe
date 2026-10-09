<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\ChildrenFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardHasChildren implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.children.exist';
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
        return [ChildrenFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->get(ChildrenFacts::class)->childCount > 0;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_has_children' : 'workflow.waiting.card_has_children');
    }
}
