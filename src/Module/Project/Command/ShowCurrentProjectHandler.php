<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

/**
 * Reads how a project names itself to a caller outside the application.
 *
 * The identity travels as scalars rather than as the entity alone, so the shape
 * a caller reads is fixed here and not in each caller.
 */
final readonly class ShowCurrentProjectHandler
{
    public function __invoke(ShowCurrentProjectCommand $command): CurrentProjectView
    {
        $project = $command->project;

        return new CurrentProjectView(
            project: $project,
            id: (string) $project->id,
            slug: $project->slug,
            name: $project->name,
        );
    }
}
