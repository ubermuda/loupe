<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopReadiness;
use App\Module\Readiness\Entity\DiscoveryRun;

final readonly class ReadinessView
{
    public function __construct(
        public Project $project,
        public bool $guideHidden,
        public WorkshopReadiness $readiness,
        /** The latest discovery run of the project, or null when discovery never ran. */
        public ?DiscoveryRun $discovery,
    ) {
    }
}
