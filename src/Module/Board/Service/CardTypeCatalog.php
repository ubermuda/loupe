<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Project\Entity\Project;

/** Board may not import Workflow, so the workflow template of the project declares its card types. */
interface CardTypeCatalog
{
    public function forProject(Project $project): CardTypes;
}
