<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;

final readonly class RequestWork implements Action
{
    public function __construct(
        private WorkRequestOpener $opener,
    ) {
    }

    #[\Override]
    public static function type(): ActionType
    {
        return ActionType::Request;
    }

    #[\Override]
    public function run(Rule $rule, Card $card, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        $limit = ActionParams::optionalInt($rule, 'limit');
        if (null !== $limit && $state->fires >= $limit) {
            return ActionOutcome::pause(CardPauseKind::WorkLimit, 'work-limit-reached');
        }

        return $this->opener->open($rule, $card, ActionParams::string($rule, 'kind'), ActionParams::optionalString($rule, 'capability'));
    }
}
