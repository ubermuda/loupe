<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

/** The parent card sits in the given slot. */
final readonly class ParentInSlot implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'parent.in_slot';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('slot', ParameterType::Slot)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [EngineFact::ParentSlot];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return ParameterValue::string($params, 'slot') === $facts->parentSlot;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.parent_in_slot' : 'workflow.waiting.parent_in_slot', ['%slot%' => ParameterValue::string($params, 'slot')]);
    }
}
