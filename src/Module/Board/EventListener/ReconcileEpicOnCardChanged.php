<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Event\CardParentChanged;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps an epic in step with its children, and starts a child whose last
 * blocker finished. Each move goes through UpdateCardHandler as the app, in a
 * SAVEPOINT of the change that caused it, so a failure here refuses that change.
 *
 * The chain ends: an epic has no parent, and a card this releases lands in an
 * open column, which closes no epic and releases nothing.
 */
final readonly class ReconcileEpicOnCardChanged
{
    public const string REOPEN_SLUG = 'implementation';

    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private UpdateCardHandler $updateCard,
        private BoardAvailability $board,
    ) {
    }

    // Below the default priority, so the outbox row of the causing move is written first.
    #[AsEventListener(priority: -5)]
    public function onCardMoved(CardMoved $event): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }

        $card = $event->card;
        // The move itself does not read the parent under the lock.
        $this->cards->refreshTypeAndParent($card);
        if (null !== $card->parent) {
            $this->reconcile($card->parent);
        }

        if ($card->column->terminal && !$event->move->fromColumn->terminal) {
            $this->release($card);
        }
    }

    #[AsEventListener(priority: -5)]
    public function onCardParentChanged(CardParentChanged $event): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }

        foreach ([$event->oldParent, $event->newParent] as $epic) {
            if (null !== $epic) {
                $this->reconcile($epic);
            }
        }
    }

    /** Closes an epic whose children are all finished, and reopens a closed one with an open child. */
    private function reconcile(Card $epic): void
    {
        $columns = $this->boardColumns->findForProjectFresh($epic->project);
        $this->cards->refreshColumn($epic);
        if (0 === $this->cards->countChildren($epic)) {
            return;
        }

        $open = [] !== $this->cards->openChildNumbers($epic);
        $target = match (true) {
            !$open && !$epic->column->terminal => array_find($columns, static fn (BoardColumn $column): bool => $column->terminal),
            $open && $epic->column->terminal => self::reopenColumn($columns),
            default => null,
        };

        if (null !== $target) {
            $this->move($epic, $target);
        }
    }

    /** Starts each waiting child that the finished card was the last open blocker of. */
    private function release(Card $blocker): void
    {
        $freed = $this->cards->findChildrenFreedBy($blocker);
        if ([] === $freed) {
            return;
        }

        $target = self::reopenColumn($this->boardColumns->findForProjectFresh($blocker->project));
        if (null === $target) {
            return;
        }

        foreach ($freed as $card) {
            $this->move($card, $target);
        }
    }

    private function move(Card $card, BoardColumn $column): void
    {
        ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::System, column: $column));
    }

    /** @param list<BoardColumn> $columns */
    private static function reopenColumn(array $columns): ?BoardColumn
    {
        return array_find($columns, static fn (BoardColumn $column): bool => self::REOPEN_SLUG === $column->slug);
    }
}
