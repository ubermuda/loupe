<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;

/**
 * A full-text search over one project's board.
 *
 * The query arrives raw. The handler trims it and refuses a blank one, so every
 * caller gets the same answer rather than each entry point deciding for itself.
 */
final readonly class SearchBoardCommand
{
    public function __construct(
        public Project $project,
        public string $query,
        public int $page,
        public int $perPage,
    ) {
    }
}
