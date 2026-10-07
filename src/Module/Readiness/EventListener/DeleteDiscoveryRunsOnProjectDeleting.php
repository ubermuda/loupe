<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Bulk-deletes the discovery runs of a project, inside ProjectDeleter's transaction. */
#[AsEventListener]
final readonly class DeleteDiscoveryRunsOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery(
            'DELETE App\Module\Readiness\Entity\DiscoveryRun r WHERE r.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
