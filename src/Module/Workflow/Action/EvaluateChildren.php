<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;

/** Asks the engine to evaluate every child of the card again. */
final readonly class EvaluateChildren implements Action
{
    public function __construct(
        private CardRepository $cards,
        private CardEvaluations $evaluations,
    ) {
    }

    #[\Override]
    public static function type(): ActionType
    {
        return ActionType::Evaluate;
    }

    #[\Override]
    public function run(Rule $rule, Card $card, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        $ids = array_values(array_filter(array_map(static fn (Card $child) => $child->id, $this->cards->findChildren($card))));
        if ([] !== $ids) {
            $this->evaluations->forCards($ids);
        }

        return ActionOutcome::done();
    }
}
