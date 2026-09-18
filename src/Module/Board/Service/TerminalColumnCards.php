<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\CardRepository;

/**
 * Brings the cards of a column along after its terminal flag changed. A
 * terminal column shows its cards by completion and a column that is not
 * terminal shows them by rank, so a card left without the field its column
 * reads would drop off the board.
 */
final readonly class TerminalColumnCards
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    public function follow(BoardColumn $column, \DateTimeImmutable $now): void
    {
        if ($column->terminal) {
            $this->cards->stampCompletion($column, $now);

            return;
        }

        // Every card of a terminal column sits at rank 0, so the renumber
        // ranks each group by completion, and it must run before the
        // completion is cleared.
        $this->cards->renumberColumn($column, $now);
        $this->cards->clearCompletion($column, $now);
    }
}
