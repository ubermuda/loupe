<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Project\Entity\Project;

/**
 * Dispatched after a change to the board's layout commits: a column add,
 * rename, reorder, flag change or delete, an epic lane that appears or
 * disappears, or a new terminal window. An update that runs inside another handler's transaction
 * dispatches it before that commit, so a rollback costs a board one reload.
 */
final readonly class BoardColumnsChanged
{
    public function __construct(
        public Project $project,
    ) {
    }
}
