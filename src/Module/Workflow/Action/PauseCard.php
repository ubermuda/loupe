<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;

/** Answers a pause with the reason of the rule. The engine writes the pause and reads its release condition. */
final readonly class PauseCard implements Action
{
    #[\Override]
    public static function type(): ActionType
    {
        return ActionType::Pause;
    }

    #[\Override]
    public function run(Rule $rule, CardSnapshot $card, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        return ActionOutcome::pause(PauseKind::Rule, ActionParams::string($rule, 'reason'));
    }
}
