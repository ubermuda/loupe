<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Exception\DomainErrors;
use App\Module\Board\Command\EpicChildrenOpen;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\SlotKeys;

/** Moves a card to the column of a slot, of the backlog, or of the first terminal column, and only from the slot `from` names. */
final readonly class MoveCard implements Action
{
    public const string KEY = 'move';

    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private UpdateCardHandler $updateCard,
    ) {
    }

    #[\Override]
    public static function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [
            new Parameter('to', ParameterType::Slot),
            new Parameter('from', ParameterType::Slot, required: false),
        ];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(endsPass: true, option: true);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        $to = (string) $params['to'];

        return match ($to) {
            SlotKeys::BACKLOG => new ActionDescription('workflow.settings.action.move', 'workflow.panel.action.move_backlog', settingsTarget: $to),
            SlotKeys::TERMINAL => new ActionDescription('workflow.settings.action.move', 'workflow.panel.action.move_terminal', settingsTarget: $to),
            default => new ActionDescription('workflow.settings.action.move', 'workflow.panel.action.move', panelSlots: ['%slot%' => $to], settingsTarget: $to),
        };
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        $card = $this->cards->find($context->card->id) ?? throw new \LogicException('A stored card has an id.');
        $from = $context->optionalString('from');
        if (null !== $from && $from !== $context->facts->card->slot) {
            return ActionOutcome::done();
        }
        $target = $context->column('to');
        $column = null === $target ? null : $this->boardColumns->find($target->id);
        if (null === $column) {
            return ActionOutcome::refused('workflow-slot-missing');
        }
        if ($column === $card->column) {
            return ActionOutcome::done();
        }

        // A card that left its column since the facts were read stays where it is, and the handler answers with no error.
        try {
            ($this->updateCard)(new UpdateCardCommand(
                card: $card,
                actor: Actor::System,
                column: $column,
                onlyFromColumn: $card->column,
                cause: CardEventCause::workflowRule($context->ruleId),
            ));
        } catch (DomainErrors) {
            return ActionOutcome::refused('move-refused');
        } catch (EpicChildrenOpen) {
            return ActionOutcome::refused('epic-children-open');
        }

        return ActionOutcome::done();
    }
}
