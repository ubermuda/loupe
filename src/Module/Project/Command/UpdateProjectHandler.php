<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Utils\Slug;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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

        // Derived on every save, not only on a rename: an image older than the slug
        // column can leave a slug empty, or stale after its own rename. A suffixed
        // slug stays only while another project holds the plain one.
        $slug = Slug::forName($command->name, $project->slug);
        if ('' === $slug) {
            throw new DomainErrors(['name' => 'project.error.slug_empty']);
        }
        $plain = Slug::fromName($command->name);
        if ($slug !== $plain && \in_array($this->projects->findOneByOwnerAndSlug($project->owner, $plain), [null, $project], true)) {
            $slug = $plain;
        }
        $holder = $this->projects->findOneByOwnerAndSlug($project->owner, $slug);
        if (null !== $holder && $holder !== $project) {
            throw new DomainErrors(['name' => 'project.error.slug_taken']);
        }

        $project->name = $command->name;
        $project->slug = $slug;
        $project->domain = $command->domain;
        // Documents keep the language they were written with, so this only
        // changes what a document created after it inherits.
        $project->searchLanguage = $command->searchLanguage;
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent rename won the race with the checks above, and a unique index caught it.
            throw new DomainErrors(['name' => str_contains($e->getMessage(), Project::SLUG_CONSTRAINT) ? 'project.error.slug_taken' : 'project.error.name_taken']);
        }

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
