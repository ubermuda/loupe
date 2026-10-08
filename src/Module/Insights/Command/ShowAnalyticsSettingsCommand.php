<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowAnalyticsSettingsCommand
{
    public function __construct(
        public Project $project,
    ) {
    }
}
