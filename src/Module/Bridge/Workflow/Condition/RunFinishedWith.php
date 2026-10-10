<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\Workflow\LatestWorkFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

/** The latest work request of the card, in any state, is done, of the given kind, with the given reason. */
final readonly class RunFinishedWith implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.run.finished_with';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.bridge';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('kind', ParameterType::String), new Parameter('reason', ParameterType::String)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [LatestWorkFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $latest = $facts->get(LatestWorkFacts::class);

        return WorkRequestState::Done === $latest->state
            && ParameterValue::string($params, 'kind') === $latest->kind
            && ParameterValue::string($params, 'reason') === $latest->reason;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage(
            $negated ? 'workflow.waiting.not.run_finished_with' : 'workflow.waiting.run_finished_with',
            ['%kind%' => ParameterValue::string($params, 'kind'), '%reason%' => ParameterValue::string($params, 'reason')],
        );
    }
}
