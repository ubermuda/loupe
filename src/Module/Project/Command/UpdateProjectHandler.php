<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UpdateProjectHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private Auditor $auditor,
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
        // Documents keep the language they were written with, so this only
        // changes what a document created after it inherits.
        $project->searchLanguage = $command->searchLanguage;
        $this->em->flush();

        $this->auditor->record(
            'project.updated',
            AuditOutcome::Success,
            [
                'projectId' => (string) $project->id,
                // Whose project it is, not who acted — the Auditor resolves the
                // actor itself. The two coincide only while a project is
                // editable by its owner alone.
                'ownerId' => (string) $project->owner->id,
            ],
            new AuditSubject('project', (string) $project->id),
        );

        return $project;
    }
}
