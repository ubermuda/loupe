<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;
use App\Module\Project\Stats\ProjectStats;

final readonly class ShowWorkshopView
{
    public function __construct(
        public Project $project,
        public ProjectStats $stats,
    ) {
    }
}
