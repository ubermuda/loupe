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

/** A worker run of the card is open, of the given work kind, or of any kind when no kind is given. */
final readonly class RunWorkerActive implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'run.worker_active';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.bridge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('kind', ParameterType::String, required: false)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::WorkerRuns];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $kind = ParameterValue::optionalString($params, 'kind');
        $active = $facts->run->activeWorkerKinds;

        return null === $kind ? [] !== $active : \in_array($kind, $active, true);
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.run_worker_active' : 'workflow.waiting.run_worker_active');
    }
}
