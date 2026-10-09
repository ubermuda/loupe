<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Project\Entity\Project;

/** Board may not import Workflow, so the workflow engine says which writes a verdict of this project starts. */
interface VerdictActionPreview
{
    /**
     * @return list<string> a short code for each forge write that a verdict of this kind starts directly, such as `post-review`
     */
    public function actionsFor(Project $project, CardVerdictKind $kind): array;
}
