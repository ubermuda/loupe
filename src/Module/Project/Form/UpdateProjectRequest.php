<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use App\Module\Project\Entity\Project;

/**
 * Identical fields and constraints to {@see CreateProjectRequest}; the only addition is
 * the factory that pre-fills the edit form from the existing project.
 */
class UpdateProjectRequest extends CreateProjectRequest
{
    public static function fromProject(Project $project): self
    {
        return new self($project->name, $project->domain, $project->searchLanguage);
    }
}
