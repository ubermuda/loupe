<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Project\Entity\Project;

/**
 * Dispatched after a change to the board's layout commits: a column add,
 * rename, reorder, flag change or delete, or an epic lane turned on or off. A
 * listener sees only committed state, so it may call out of the process, and a
 * rollback dispatches nothing.
 */
final readonly class BoardColumnsChanged
{
    public function __construct(
        public Project $project,
    ) {
    }
}
