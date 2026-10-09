<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Workflow\Contract\Actor;

final readonly class BulkMoveBacklogCardsCommand
{
    /** @param list<string> $cardIds cards of the Backlog, at most BulkMoveBacklogCardsHandler::MAX_CARDS */
    public function __construct(
        /** The Backlog of one board. */
        public BoardColumn $backlog,
        public array $cardIds,
        public Actor $actor,
        /** A column the board draws. The cards land at its end, in Backlog order. */
        public BoardColumn $column,
    ) {
    }
}
