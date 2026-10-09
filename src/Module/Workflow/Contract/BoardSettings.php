<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Reads the board automation settings of a project for the workflow. Board implements it. */
interface BoardSettings
{
    public function automationEnabled(Uuid $projectId): bool;
}
