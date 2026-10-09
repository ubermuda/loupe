<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Board\Workflow\CardTypeFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardHasType implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.type';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('type', ParameterType::String)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [CardTypeFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return ParameterValue::string($params, 'type') === $facts->get(CardTypeFacts::class)->type;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_type' : 'workflow.waiting.card_type', ['%type%' => ParameterValue::string($params, 'type')]);
    }
}
