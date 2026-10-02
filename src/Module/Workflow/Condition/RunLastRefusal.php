<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class RunLastRefusal implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'run.last_refusal';
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
    public function waitingFor(array $params): TranslatableMessage
    {
        return new TranslatableMessage('workflow.waiting.run_last_refusal', ['%code%' => ParameterValue::string($params, 'code')]);
    }
}
