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
        /** The id of the card before it in its column, or null when it comes first. */
        public ?string $after,
        /** The id of the card before it in the list view, which runs column by column. */
        public ?string $rowAfter,
        public int $pendingComments,
        /** @var array<string, int> column id => the number of cards the column shows */
        public array $counts,
        /** @var array<string, int> terminal column id => every card the column holds */
        public array $terminalTotals,
        /** The done and total children of an epic, null for any other card. */
        public ?CardProgress $progress = null,
    ) {
    }
}
