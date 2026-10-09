<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Exception\DomainErrors;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;

/** Removes the parent of the card. A card with no parent stays as it is. */
final readonly class Detach implements Action
{
    public const string DETACH_REFUSED = 'detach-refused';

    public function __construct(
        private UpdateCardHandler $updateCard,
    ) {
    }

    #[\Override]
    public static function type(): ActionType
    {
        return ActionType::Detach;
    }

    #[\Override]
    public function run(Rule $rule, Card $card, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        if (!$facts->card->isChild) {
            return ActionOutcome::done();
        }

        try {
            ($this->updateCard)(new UpdateCardCommand(
                card: $card,
                actor: Actor::System,
                parentCardId: '',
                cause: CardEventCause::workflowRule($rule->id),
            ));
        } catch (DomainErrors) {
            return ActionOutcome::refused(self::DETACH_REFUSED);
        }

        return ActionOutcome::done();
    }
}
