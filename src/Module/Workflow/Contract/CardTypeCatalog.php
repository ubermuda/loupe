<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Board asks this port for the card types of a project, and the workflow template of the project declares them. */
interface CardTypeCatalog
{
    public function forProject(Uuid $projectId): CardTypes;
}
