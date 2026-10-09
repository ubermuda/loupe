<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Exception\DomainErrors;
use App\Module\Board\Command\EpicChildrenOpen;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Service\CardEventCause;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;

/** Moves a card to the column of a slot, of the backlog, or of the first terminal column, and only from the slot `from` names. */
final readonly class MoveCard implements Action
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private UpdateCardHandler $updateCard,
    ) {
    }

    #[\Override]
    public static function type(): ActionType
    {
        return ActionType::Move;
    }

    #[\Override]
    public function run(Rule $rule, Card $card, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        $from = ActionParams::optionalString($rule, 'from');
        if (null !== $from && $from !== $facts->card->slot) {
            return ActionOutcome::done();
        }
        $target = $this->columnFor($card, ActionParams::string($rule, 'to'));
        if (null === $target) {
            return ActionOutcome::refused('workflow-slot-missing');
        }
        if ($target === $card->column) {
            return ActionOutcome::done();
        }

        // A card that left its column since the facts were read stays where it is, and the handler answers with no error.
        try {
            ($this->updateCard)(new UpdateCardCommand(
                card: $card,
                actor: CardReporter::System,
                column: $target,
                onlyFromColumn: $card->column,
                cause: CardEventCause::workflowRule($rule->id),
            ));
        } catch (DomainErrors) {
            return ActionOutcome::refused('move-refused');
        } catch (EpicChildrenOpen) {
            return ActionOutcome::refused('epic-children-open');
        }

        return ActionOutcome::done();
    }

    public function columnFor(Card $card, string $to): ?BoardColumn
    {
        $projectId = (string) ($card->project->id ?? throw new \LogicException('A stored card has a project id.'));

        return match ($to) {
            FactsBuilder::BACKLOG_SLOT => $this->boardColumns->findBacklogForProjectId($projectId),
            FactsBuilder::TERMINAL_SLOT => $this->boardColumns->findFirstTerminalForProjectId($projectId),
            default => $this->workflowSlotLinks->findColumnForSlot($card->project, $to),
        };
    }
}
