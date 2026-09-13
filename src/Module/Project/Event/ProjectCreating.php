<?php

declare(strict_types=1);

namespace App\Module\Project\Event;

use App\Module\Project\Entity\Project;

/**
 * Dispatched after a new project is persisted and before the flush that
 * inserts it. A listener persists its own rows and never flushes, so they
 * commit with the project or not at all.
 */
final readonly class ProjectCreating
{
    public function __construct(
        public Project $project,
    ) {
    }
}
