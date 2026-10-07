<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

use App\Module\Project\Entity\Project;

interface WorkshopReadinessProviderInterface
{
    /** Null once the owner hides the guide. */
    public function forProject(Project $project): ?WorkshopReadiness;
}
