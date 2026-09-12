<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
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
        public ?CardStatus $status = null,
        public ?CardType $type = null,
        public ?CardPriority $priority = null,
        public ?CardOrigin $reporter = null,
        public int $page = 1,
        public int $perPage = ListCardsHandler::DEFAULT_PER_PAGE,
    ) {
    }
}
