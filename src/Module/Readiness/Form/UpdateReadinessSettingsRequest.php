<?php

declare(strict_types=1);

namespace App\Module\Readiness\Form;

use App\Module\Project\Entity\Project;

class UpdateReadinessSettingsRequest
{
    public function __construct(
        public bool $showGuide = true,
    ) {
    }

    public static function fromProject(Project $project): self
    {
        return new self(showGuide: null === $project->readinessGuideHiddenAt);
    }
}
