<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

use App\Module\Project\Entity\Project;

interface WorkshopConnectionsProviderInterface
{
    /** @return list<WorkshopConnection> */
    public function forProject(Project $project): array;
}
