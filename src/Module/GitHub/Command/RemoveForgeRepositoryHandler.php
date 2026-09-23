<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\Forge\Service\ForgeRepositories;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class RemoveForgeRepositoryHandler
{
    public function __construct(
        private ForgeRepositories $forgeRepositories,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RemoveForgeRepositoryCommand $command): void
    {
        $project = $command->project;
        $repository = $command->repository;
        if (null === $project->id || true !== $repository->project->id?->equals($project->id)) {
            throw new DomainErrors(['repository' => 'github.repositories.flash.repository_missing']);
        }

        $repositoryId = (string) $repository->id;
        $this->forgeRepositories->release($project, $repository->forge, $repository->externalId);

        $this->auditor->record(
            'github.repository_removed',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id, 'repositoryId' => $repositoryId, 'forge' => $repository->forge],
            new AuditSubject('project', (string) $project->id),
        );
    }
}
