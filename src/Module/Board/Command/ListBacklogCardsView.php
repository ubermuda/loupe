<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;

final readonly class ListBacklogCardsView
{
    /**
     * @param list<Card>         $items
     * @param array<string, int> $pendingComments pending feedback per card id; a card with none is absent
     * @param list<int|null>     $pageList
     * @param list<Card>         $epics           the epics of the Backlog cards, for the epic filter
     * @param list<BoardColumn>  $boardColumns    the columns a card can move to, in board order
     */
    public function __construct(
        public array $items,
        public array $pendingComments,
        /** Every card in the Backlog, whatever the filters. */
        public int $total,
        /** The cards the filters match. */
        public int $filteredTotal,
        public int $totalPages,
        public array $pageList,
        /** The page to redirect to when the request asked for one past the end, else null. */
        public ?int $clampedPage,
        public array $epics,
        public array $boardColumns,
        /** The first open column the board draws, which the bulk bar names. */
        public ?BoardColumn $nextColumn,
    ) {
    }
}
