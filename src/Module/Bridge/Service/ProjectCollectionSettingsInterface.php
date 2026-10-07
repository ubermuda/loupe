<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Project\Entity\Project;

/** Bridge may not import Insights, so Insights answers this from the project settings. */
interface ProjectCollectionSettingsInterface
{
    public function collectFullText(Project $project): bool;
}
