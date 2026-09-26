<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Deletes the run rows and the usage rows of a project that is going away. A
 * usage row outlives its run, so it goes on its own. Runs inside
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
        // The same lock the report handler takes. Without it a report that
        // commits between this delete and the project row's own delete leaves a
        // child the foreign key then refuses.
        $this->em->lock($event->project, LockMode::PESSIMISTIC_WRITE);

        $this->em->createQuery(
            'DELETE App\Module\Bridge\Entity\WorkerRun r WHERE r.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Bridge\Entity\WorkerRunUsage u WHERE u.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
