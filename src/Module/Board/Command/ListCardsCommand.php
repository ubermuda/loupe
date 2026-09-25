<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;

/**
 * One filtered, paginated read of a project's board.
 *
 * The page and the page size arrive raw. The handler clamps both, so every
 * caller gets the same answer rather than each entry point deciding for itself.
 */
final readonly class ListCardsCommand
{
    public function __construct(
        public Project $project,
        /** A column of this project's board. Null reads every column. */
        public ?BoardColumn $column = null,
        public ?CardType $type = null,
        public ?CardReporter $reporter = null,
        public int $page = 1,
        public int $perPage = ListCardsHandler::DEFAULT_PER_PAGE,
        /** A card of this project. Null reads every card, with or without a parent. */
        public ?Card $parent = null,
    ) {
    }
}
