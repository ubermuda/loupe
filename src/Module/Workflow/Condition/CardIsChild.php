<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardIsChild implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.is_child';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::Parent];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return $facts->card->isChild;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_is_child' : 'workflow.waiting.card_is_child');
    }
}
