<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;

/** One column of the board, with the cards the page shows in it. */
final readonly class BoardColumnView
{
    /** @param list<BoardGroupView> $groups */
    public function __construct(
        public BoardColumn $column,
        public array $groups,
        public int $count,
        /** Every card a terminal column holds, which is more than it shows. Null for any other column. */
        public ?int $terminalTotal = null,
    ) {
    }
}
