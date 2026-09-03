<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class CreateProjectHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private Auditor $auditor,
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

        $this->auditor->record(
            'project.created',
            AuditOutcome::Success,
            [
                'projectId' => (string) $project->id,
                // Whose project it is, not who acted — the Auditor resolves the
                // actor itself. The two coincide only while a project is
                // editable by its owner alone.
                'ownerId' => (string) $command->owner->id,
            ],
            new AuditSubject('project', (string) $project->id),
        );

        return $project;
    }
}
