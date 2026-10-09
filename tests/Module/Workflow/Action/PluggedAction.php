<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\ChecksParameters;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;

/** An action a module could plug in, to show that the parser reads only the declarations. */
final readonly class PluggedAction implements Action, ChecksParameters
{
    #[\Override]
    public static function key(): string
    {
        return 'plugged';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [
            new Parameter('from', ParameterType::Slot),
            new Parameter('times', ParameterType::Int, min: 2),
            new Parameter('mode', ParameterType::String, required: false, choices: ['fast', 'safe']),
        ];
    }

    #[\Override]
    public static function check(array $params): array
    {
        return 'safe' === ($params['mode'] ?? null) && 'build' !== ($params['from'] ?? null) ? ['safe mode needs the slot build'] : [];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(endsPass: true);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.plugged', 'workflow.panel.action.plugged');
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        return ActionOutcome::done();
    }
}
