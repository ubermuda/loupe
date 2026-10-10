<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Board asks this port for the branch of an epic, and the workflow template of the project names it. */
interface EpicBranches
{
    /** @return ?string the epic branch of the card number, or null when the template has no epic branches */
    public function of(Uuid $projectId, int $number): ?string;
}
