<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Project\Service\ProjectDeleter;

final readonly class DeleteProjectHandler
{
    public function __construct(
        private ProjectDeleter $projectDeleter,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(DeleteProjectCommand $command): void
    {
        if ($command->confirmedName !== $command->project->name) {
            throw new DomainErrors(['confirmName' => 'project.delete.error.name_mismatch']);
        }

        $projectId = (string) $command->project->id;

        $this->projectDeleter->delete($command->project);

        // Recorded here as well as in ProjectDeleter, which also runs under
        // account deletion and cannot tell a deliberate delete from a cascade.
        // After the delete, because the record outlives a rolled-back delete.
        $this->auditor->record(
            'project.deletion_requested',
            AuditOutcome::Success,
            ['projectId' => $projectId],
            new AuditSubject('project', $projectId),
        );
    }
}
