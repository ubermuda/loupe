<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Board\Entity\Card;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;

/** Does nothing. The rule of an action that this version does not know never reaches it, because the rule never fires. */
final readonly class MissingAction implements Action
{
    #[\Override]
    public static function type(): ActionType
    {
        return ActionType::Missing;
    }

    #[\Override]
    public function run(Rule $rule, Card $card, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        return ActionOutcome::done();
    }
}
