<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class CreateProjectHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CreateProjectCommand $command): Project
    {
        if (null !== $this->projects->findOneByOwnerAndName($command->owner, $command->name)) {
            throw new DomainErrors(['name' => 'project.error.name_taken']);
        }

        $project = new Project($command->owner, $command->name, $command->domain);

        try {
            $this->em->persist($project);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent create won the race with the check above; uniq_project_owner_name
            // caught it, so surface the same field error rather than letting the request 500.
            throw new DomainErrors(['name' => 'project.error.name_taken']);
        }

        $this->logger->info('project.created', [
            'projectId' => (string) $project->id,
            'ownerId' => (string) $command->owner->id,
        ]);

        return $project;
    }
}
