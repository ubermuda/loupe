<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Returns the named project for an owner, creating it if this is the first
 * call. Used by the dev seeding endpoints, which must be callable repeatedly
 * without accumulating duplicate projects.
 */
final readonly class EnsureHarnessProjectHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(EnsureHarnessProjectCommand $command): Project
    {
        $project = $this->projects->findOneByOwnerAndName($command->owner, $command->name);
        if (null !== $project) {
            return $project;
        }

        $project = new Project($command->owner, $command->name);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
