<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Carries out the action of a rule on one card. A template refers to it by its type. */
#[AutoconfigureTag('app.workflow_action')]
interface Action
{
    public static function type(): ActionType;

    public function run(Rule $rule, CardSnapshot $card, Facts $facts, WorkflowRuleState $state): ActionOutcome;
}
