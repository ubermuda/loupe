<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;

/**
 * How one project identifies itself to a caller outside the application.
 *
 * The slug is null on a project created before the column existed, so the id is
 * the identifier a caller quotes.
 */
final readonly class CurrentProjectView
{
    public function __construct(
        public Project $project,
        public string $id,
        public ?string $slug,
        public string $name,
    ) {
    }
}
