<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Exception\DomainErrors;
use App\Module\Board\Command\EpicChildrenOpen;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;
use Symfony\Component\Uid\Uuid;

/** Moves a card to the column of a slot, of the backlog, or of the first terminal column, and only from the slot `from` names. */
final readonly class MoveCard implements Action
{
    public function __construct(
        private CardRepository $cards,
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
    public function run(Rule $rule, CardSnapshot $snapshot, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        $card = $this->cards->find($snapshot->id) ?? throw new \LogicException('A stored card has an id.');
        $from = ActionParams::optionalString($rule, 'from');
        if (null !== $from && $from !== $facts->card->slot) {
            return ActionOutcome::done();
        }
        $target = $this->column($card, ActionParams::string($rule, 'to'));
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
                actor: Actor::System,
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

    private function boardColumnById(?Uuid $columnId): ?BoardColumn
    {
        return null === $columnId ? null : $this->boardColumns->find($columnId);
    }

    private function column(Card $card, string $to): ?BoardColumn
    {
        $projectId = (string) ($card->project->id ?? throw new \LogicException('A stored card has a project id.'));

        return match ($to) {
            FactsBuilder::BACKLOG_SLOT => $this->boardColumns->findBacklogForProjectId($projectId),
            FactsBuilder::TERMINAL_SLOT => $this->boardColumns->findFirstTerminalForProjectId($projectId),
            default => $this->boardColumnById($this->workflowSlotLinks->findColumnIdForSlot($card->project, $to)),
        };
    }
}
