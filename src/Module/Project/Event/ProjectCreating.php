<?php

declare(strict_types=1);

namespace App\Module\Project\Event;

use App\Module\Project\Entity\Project;

/**
 * Dispatched after a new project is persisted and before the flush that
 * inserts it. A listener persists its own rows and never flushes, so they
 * commit with the project or not at all. A listener that seeds the board
 * columns marks the event, and the default seeder then skips.
 */
final class ProjectCreating
{
    public private(set) bool $columnsSeeded = false;

    public function __construct(
        public readonly Project $project,
        public readonly ?string $workflowTemplate = null,
    ) {
    }

    public function markColumnsSeeded(): void
    {
        $this->columnsSeeded = true;
    }
}
