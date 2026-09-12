<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;

/** The columns of one project's board, in board order. */
final readonly class ListBoardColumnsView
{
    /** @param list<BoardColumn> $columns */
    public function __construct(
        public array $columns,
    ) {
    }
}
