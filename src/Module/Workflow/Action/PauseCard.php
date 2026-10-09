<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\PauseKind;

/** Answers a pause with the reason of the rule. The engine writes the pause and reads its release condition. */
final readonly class PauseCard implements Action
{
    public const string KEY = 'pause';

    #[\Override]
    public static function key(): string
    {
        return self::KEY;
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
            new Parameter('reason', ParameterType::String),
            new Parameter('until', ParameterType::Expression),
        ];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits();
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.pause', 'workflow.panel.action.pause');
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        return ActionOutcome::pause(PauseKind::Rule, $context->string('reason'));
    }
}
