<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectCreating;
use App\Module\Project\Repository\ProjectRepository;
use App\Utils\Slug;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class CreateProjectHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(CreateProjectCommand $command): Project
    {
        if (null !== $this->projects->findOneByOwnerAndName($command->owner, $command->name)) {
            throw new DomainErrors(['name' => 'project.error.name_taken']);
        }

        $slug = Slug::fromName($command->name);
        if ('' === $slug) {
            throw new DomainErrors(['name' => 'project.error.slug_empty']);
        }
        if (null !== $this->projects->findOneByOwnerAndSlug($command->owner, $slug)) {
            throw new DomainErrors(['name' => 'project.error.slug_taken']);
        }

        $project = new Project($command->owner, $command->name, $command->domain);
        $description = trim($command->description ?? '');
        $project->description = '' === $description ? null : $description;
        $project->searchLanguage = $command->searchLanguage;

        try {
            $this->em->persist($project);
            $this->events->dispatch(new ProjectCreating($project));
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent create won the race with the checks above, and a unique index caught it.
            throw new DomainErrors(['name' => str_contains($e->getMessage(), Project::SLUG_CONSTRAINT) ? 'project.error.slug_taken' : 'project.error.name_taken']);
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
