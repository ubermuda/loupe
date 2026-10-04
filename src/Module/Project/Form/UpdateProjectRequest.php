<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use App\Module\Project\Entity\Project;

/**
 * The fields and constraints of {@see CreateProjectRequest}, plus the factory that
 * pre-fills the edit form. The edit form leaves out the workflow template.
 */
class UpdateProjectRequest extends CreateProjectRequest
{
    public static function fromProject(Project $project): self
    {
        return new self($project->name, $project->domain, $project->searchLanguage, $project->description);
    }
}
