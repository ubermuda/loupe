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

final readonly class RunLastRefusal implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'run.last_refusal';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.bridge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('code', ParameterType::String)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::Refusal];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return ParameterValue::string($params, 'code') === $facts->run->lastRefusalCode;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.run_last_refusal' : 'workflow.waiting.run_last_refusal', ['%code%' => ParameterValue::string($params, 'code')]);
    }
}
