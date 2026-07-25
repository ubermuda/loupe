<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Project\Service\ProjectDeleter;

final readonly class DeleteProjectHandler
{
    public function __construct(private ProjectDeleter $projectDeleter)
    {
    }

    public function __invoke(DeleteProjectCommand $command): void
    {
        if ($command->confirmedName !== $command->project->name) {
            throw new DomainErrors(['confirmName' => 'project.delete.error.name_mismatch']);
        }

        $this->projectDeleter->delete($command->project);
    }
}
