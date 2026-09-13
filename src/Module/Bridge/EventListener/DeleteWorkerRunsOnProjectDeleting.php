<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Deletes the run rows of a project that is going away. Runs inside
 * ProjectDeleter's transaction.
 */
#[AsEventListener]
final readonly class DeleteWorkerRunsOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery(
            'DELETE App\Module\Bridge\Entity\WorkerRun r WHERE r.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
