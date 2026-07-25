<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class UpdateProjectHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UpdateProjectCommand $command): Project
    {
        $project = $command->project;

        // The (owner, name) pair is unique. A rename may only collide with a *different*
        // project — renaming to the same name (or only editing the domain) is fine.
        $existing = $this->projects->findOneByOwnerAndName($project->owner, $command->name);
        if (null !== $existing && $existing !== $project) {
            throw new DomainErrors(['name' => 'project.error.name_taken']);
        }

        $project->name = $command->name;
        $project->domain = $command->domain;
        $this->em->flush();

        $this->logger->info('project.updated', [
            'projectId' => (string) $project->id,
            'ownerId' => (string) $project->owner->id,
        ]);

        return $project;
    }
}
