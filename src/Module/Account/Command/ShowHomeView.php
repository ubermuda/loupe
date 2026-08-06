<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowHomeView
{
    /** @param list<Project> $projects */
    public function __construct(
        public array $projects,
    ) {
    }
}
