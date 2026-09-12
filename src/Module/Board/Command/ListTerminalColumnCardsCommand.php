<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;

final readonly class ListTerminalColumnCardsCommand
{
    public function __construct(
        /** A terminal column. */
        public BoardColumn $column,
        public int $page = 1,
    ) {
    }
}
