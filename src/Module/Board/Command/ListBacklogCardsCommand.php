<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\View\BacklogListQuery;

final readonly class ListBacklogCardsCommand
{
    public function __construct(
        /** The Backlog of one board. */
        public BoardColumn $backlog,
        public BacklogListQuery $listQuery = new BacklogListQuery(),
    ) {
    }
}
