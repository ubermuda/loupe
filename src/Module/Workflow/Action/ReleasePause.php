<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;

/** Lifts the active pause of a card when its reason is the reason of the rule. */
final readonly class ReleasePause implements Action
{
    public const string RELEASE_REASON = 'released-by-rule';

    public function __construct(
        private CardPauses $cardPauses,
    ) {
    }

    #[\Override]
    public static function type(): ActionType
    {
        return ActionType::Release;
    }

    #[\Override]
    public function run(Rule $rule, CardSnapshot $card, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        $pause = $this->cardPauses->findActive($card->id);
        if (null !== $pause && $pause->reason === ActionOutcome::code(ActionParams::string($rule, 'reason'))) {
            $this->cardPauses->release($pause, self::RELEASE_REASON);
        }

        return ActionOutcome::done();
    }
}
