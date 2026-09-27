<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;

/** Where one card sits on the board page, and the count of every column. */
final readonly class CardPlacementView
{
    public function __construct(
        /** Null when the board does not show the card: it is deleted, or outside its terminal window. */
        public ?Card $card,
        public ?BoardColumn $column,
        /** The id of the card before it in its column, or in its lane cell on a board with lanes. Null when it comes first. */
        public ?string $after,
        /** The id of the card before it in the list view, which runs column by column. */
        public ?string $rowAfter,
        public int $pendingComments,
        public int $documentCount,
        /** @var array<string, int> column id => the number of cards the column shows */
        public array $counts,
        /** @var array<string, int> terminal column id => every card the column holds */
        public array $terminalTotals,
        /** The done and total children of an epic, null for any other card. */
        public ?CardProgress $progress = null,
        /** On a board with lanes, the lane that holds the card: its epic id, or "other". Null on a board with no lanes, and for a lane epic. */
        public ?string $lane = null,
        /** @var array<string, array<string, int>> lane => column id => the number of cards the lane cell shows; empty on a board with no lanes */
        public array $laneCounts = [],
        /** True when the card heads a lane, and so is no card of any cell. */
        public bool $laneEpic = false,
    ) {
    }
}
