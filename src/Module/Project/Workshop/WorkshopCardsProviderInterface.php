<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

use App\Module\Project\Entity\Project;

interface WorkshopCardsProviderInterface
{
    public function forProject(Project $project): WorkshopCardsInMotion;
}
