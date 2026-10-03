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
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_type' : 'workflow.waiting.card_type', ['%type%' => ParameterValue::string($params, 'type')]);
    }
}
