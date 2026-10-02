<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardInSlot implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.in_slot';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('slot', ParameterType::Slot)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::Slot];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return ParameterValue::string($params, 'slot') === $facts->card->slot;
    }

    #[\Override]
    public function waitingFor(array $params): TranslatableMessage
    {
        return new TranslatableMessage('workflow.waiting.card_in_slot', ['%slot%' => ParameterValue::string($params, 'slot')]);
    }
}
