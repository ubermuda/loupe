<?php

declare(strict_types=1);

namespace App\Module\Project\Event;

use App\Module\Project\Entity\Project;

/**
 * Dispatched by UpdateProjectHandler when a save changes the slug, before the
 * flush that writes it. A listener persists and never flushes, so its rows land
 * in that one flush, and a listener must never throw: it would abort the save.
 */
final readonly class ProjectRenamed
{
    public function __construct(
        public Project $project,
        public string $fromSlug,
        public string $toSlug,
        public string $actor,
    ) {
    }
}
