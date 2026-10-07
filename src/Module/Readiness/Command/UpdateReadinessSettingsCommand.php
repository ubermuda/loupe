<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Project\Entity\Project;

final readonly class UpdateReadinessSettingsCommand
{
    public function __construct(
        public Project $project,
        public bool $showGuide,
    ) {
    }
}
