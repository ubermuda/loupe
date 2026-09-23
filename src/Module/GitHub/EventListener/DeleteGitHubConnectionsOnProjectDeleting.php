<?php

declare(strict_types=1);

namespace App\Module\GitHub\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Runs inside ProjectDeleter's transaction, with no entity hydration. */
#[AsEventListener]
final readonly class DeleteGitHubConnectionsOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery('DELETE App\Module\GitHub\Entity\GitHubHook h WHERE h.project = :project')
            ->setParameter('project', $event->project)
            ->execute();

        $this->em->createQuery('DELETE App\Module\GitHub\Entity\GitHubInstallation i WHERE i.project = :project')
            ->setParameter('project', $event->project)
            ->execute();
    }
}
