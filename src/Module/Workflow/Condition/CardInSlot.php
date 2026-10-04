<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\FactKey;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
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
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_in_slot' : 'workflow.waiting.card_in_slot', ['%slot%' => ParameterValue::string($params, 'slot')]);
    }
}
