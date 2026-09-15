<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Project\Entity\Project;

/** A full-text search over one project's inbox. The query arrives raw. */
final readonly class SearchInboxCommand
{
    public function __construct(
        public Project $project,
        public string $query,
        public int $page,
        public int $perPage,
    ) {
    }
}
