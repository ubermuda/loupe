<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;

/**
 * Keeps an open column numbered from 0 with no gaps.
 *
 * One statement ranks the other cards of a column, and the ranks are read back
 * onto the loaded cards. The moving card changes in memory only, so the caller
 * runs this inside its transaction and flushes the card.
 */
final readonly class CardGroupOrder
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    /** Puts the card at the wanted rank in its own column, then renumbers the column from 0. */
    public function place(Card $card, int $position): void
    {
        $target = max(0, $position);
        $this->cards->rankWithout($card->column, $card, $target);
        $positions = $this->cards->refreshLoadedRanks($card->column, $card);

        $others = \count($positions) - (isset($positions[(string) $card->id]) ? 1 : 0);
        $card->position = min($target, $others);
    }

    /** Closes the gap a card leaves in a column. A terminal column keeps no position, so it is left alone. */
    public function compact(BoardColumn $column, Card $leaving): void
    {
        if ($column->terminal) {
            return;
        }

        $this->cards->rankWithout($column, $leaving);
        $this->cards->refreshLoadedRanks($column, $leaving);
    }
}
