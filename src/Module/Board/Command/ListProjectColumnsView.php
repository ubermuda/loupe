<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;

/** One project's columns in board order, or no project when the owner has none by that handle. */
final readonly class ListProjectColumnsView
{
    /** @param list<BoardColumn> $columns */
    public function __construct(
        public ?Project $project,
        public array $columns,
    ) {
    }
}
