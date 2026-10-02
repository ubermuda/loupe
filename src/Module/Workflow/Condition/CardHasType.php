<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardHasType implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.type';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('type', ParameterType::String)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::CardType];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return ParameterValue::string($params, 'type') === $facts->card->type;
    }

    #[\Override]
    public function waitingFor(array $params): TranslatableMessage
    {
        return new TranslatableMessage('workflow.waiting.card_type', ['%type%' => ParameterValue::string($params, 'type')]);
    }
}
