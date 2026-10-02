<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** A work request is open or claimed, of the given kind, or of any kind when no kind is given. */
final readonly class RunWorkActive implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'run.work_active';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('kind', ParameterType::String, required: false)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::WorkRequests];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $kind = ParameterValue::optionalString($params, 'kind');
        $active = $facts->run->activeWorkKinds;

        return null === $kind ? [] !== $active : \in_array($kind, $active, true);
    }

    #[\Override]
    public function waitingFor(array $params): TranslatableMessage
    {
        return new TranslatableMessage('workflow.waiting.run_work_active');
    }
}
