<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardHasChildren implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.has_children';
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
        return $facts->card->childCount > 0;
    }

    #[\Override]
    public function waitingFor(array $params): TranslatableMessage
    {
        return new TranslatableMessage('workflow.waiting.card_has_children');
    }
}
