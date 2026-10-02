<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Board\Command\ReleaseCardPauseCommand;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;

/** Lifts the active pause of a card when its reason is the reason of the rule. */
final readonly class ReleasePause implements Action
{
    public const string RELEASE_REASON = 'released-by-rule';

    public function __construct(
        private CardPauseRepository $cardPauses,
        private ReleaseCardPauseHandler $releaseCardPause,
    ) {
    }

    #[\Override]
    public static function type(): ActionType
    {
        return ActionType::Release;
    }

    #[\Override]
    public function run(Rule $rule, Card $card, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        $pause = $this->cardPauses->findActiveForCard($card);
        if (null !== $pause && $pause->reason === ActionOutcome::code(ActionParams::string($rule, 'reason'))) {
            ($this->releaseCardPause)(new ReleaseCardPauseCommand($pause, self::RELEASE_REASON));
        }

        return ActionOutcome::done();
    }
}
