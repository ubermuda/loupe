<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Project\Entity\Project;

/**
 * Dispatched after a column change commits: an add, a rename, a reorder, a
 * flag change or a delete. A listener sees only committed state, so it may call
 * out of the process, and a rollback dispatches nothing.
 */
final readonly class BoardColumnsChanged
{
    public function __construct(
        public Project $project,
    ) {
    }
}
