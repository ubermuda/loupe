<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Board\Workflow\ParentFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardIsChild implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.is_child';
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
        return [ParentFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->get(ParentFacts::class)->isChild;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_is_child' : 'workflow.waiting.card_is_child');
    }
}
