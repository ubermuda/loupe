<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;

/** The Backlog cards of one lane epic that its lane head shows as the Up next deck. */
final readonly class LaneDeckView
{
    public function __construct(
        /** The Backlog, which a drop on the deck moves a card to. */
        public BoardColumn $column,
        /** @var list<Card> the first cards in Backlog rank, at most LaneDecks::DECK_SIZE */
        public array $cards,
        /** Every Backlog child of the epic, which can be more than the deck holds. */
        public int $count,
    ) {
    }
}
