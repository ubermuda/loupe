<?php

declare(strict_types=1);

namespace App\Search\Command;

use App\Module\Project\Entity\Project;

final readonly class SearchProjectCommand
{
    public function __construct(
        public Project $project,
        public string $query = '',
        public int $page = 1,
    ) {
    }
}
