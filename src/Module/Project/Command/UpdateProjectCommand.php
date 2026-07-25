<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;

final readonly class UpdateProjectCommand
{
    public function __construct(
        public Project $project,
        /** @phpstan-var non-empty-string */
        public string $name,
        public ?string $domain,
    ) {
    }
}
