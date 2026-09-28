<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopCardsInMotion;

final readonly class WorkshopInMotionView
{
    public function __construct(
        public Project $project,
        public WorkshopCardsInMotion $inMotion,
    ) {
    }
}
