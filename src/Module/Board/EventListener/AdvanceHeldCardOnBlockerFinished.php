<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Board\Event\BoardColumnTerminalChanged;
use App\Module\Board\Event\CardBlockersRemoved;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\Service\CardEventCause;
use App\Module\Board\Service\StageHold;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Moves a card that an approval left in its stage column once its last open
 * blocker finishes or stops blocking it. The move goes through
 * UpdateCardHandler as the app, in a SAVEPOINT of the change that caused it.
 *
 * ReconcileEpicOnCardChanged releases cards that wait in the Backlog, and
 * this one cards that wait in a stage column, so the two never move one card.
 *
 * A column change must never throw, so a failed release there is logged and
 * skipped, as ResolveFeedbackOnBoardColumnTerminalChanged does.
 */
final readonly class AdvanceHeldCardOnBlockerFinished
{
    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private StageHold $hold,
        private UpdateCardHandler $updateCard,
        private BoardAvailability $board,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    // Below the default priority, so the outbox row of the causing move is written first.
    #[AsEventListener(priority: -5)]
    public function onCardMoved(CardMoved $event): void
    {
        if (!$this->board->isEnabled() || !$event->card->column->terminal || $event->move->fromColumn->terminal) {
            return;
        }

        foreach ($this->cards->findBlockedBy($event->card) as $card) {
            $this->advance($card, CardEventCause::unblocked($event->card->number));
        }
    }

    #[AsEventListener(priority: -5)]
    public function onCardBlockersRemoved(CardBlockersRemoved $event): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }

        foreach ($event->cards as $card) {
            $this->advance($card, CardEventCause::unblocked());
        }
    }

    /** A column made terminal finishes every card it holds, with no CardMoved. */
    #[AsEventListener(priority: -5)]
    public function onBoardColumnTerminalChanged(BoardColumnTerminalChanged $event): void
    {
        if ($event->terminal && $this->board->isEnabled()) {
            $this->advanceBlockedBy($event->cardIds, $event->columnId);
        }
    }

    /** A deleted open column sends its cards to the target column with no CardMoved. */
    #[AsEventListener(priority: -5)]
    public function onBoardColumnDeleted(BoardColumnDeleted $event): void
    {
        if (!$event->terminal && $event->targetTerminal && $this->board->isEnabled()) {
            $this->advanceBlockedBy($event->movedCardIds, $event->columnId);
        }
    }

    /** @param list<string> $finishedIds */
    private function advanceBlockedBy(array $finishedIds, string $columnId): void
    {
        foreach ($this->cards->findBlockedByAny($finishedIds) as $card) {
            try {
                $this->advance($card, CardEventCause::unblocked());
            } catch (\Throwable $e) {
                if ($e instanceof DbalException || !$this->em->isOpen()) {
                    throw $e;
                }
                $this->logger->warning('board.held_card_advance_failed', [
                    'cardId' => (string) $card->id,
                    'columnId' => $columnId,
                    'exception' => $e,
                ]);
            }
        }
    }

    private function advance(Card $card, CardEventCause $cause): void
    {
        // Before the blockers, because it reads the card's column onto the card.
        $stage = $this->hold->heldStage($card);
        if (null === $stage || [] !== $this->hold->openBlockers($card)) {
            return;
        }

        $target = array_find(
            $this->boardColumns->findForProjectFresh($card->project),
            static fn (BoardColumn $column): bool => $column->slug === $stage['to'],
        );
        if (null === $target) {
            return;
        }

        ($this->updateCard)(new UpdateCardCommand(
            card: $card,
            actor: CardReporter::System,
            column: $target,
            onlyFromColumn: $card->column,
            cause: $cause,
        ));
    }
}
