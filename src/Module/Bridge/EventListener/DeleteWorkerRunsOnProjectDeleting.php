<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Deletes the card holds, the commands, the work requests, the run rows, the
 * usage rows, the fact rows, the experiment pins and the experiment weights of
 * a project that is going away.
 * A usage row and a fact row outlive their run, so they go on their own. The
 * listener runs inside ProjectDeleter's transaction.
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
            'DELETE App\Module\Bridge\Entity\CardHold h WHERE h.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Bridge\Entity\BridgeCommand c WHERE c.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Bridge\Entity\WorkRequest w WHERE w.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Bridge\Entity\WorkerRun r WHERE r.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Bridge\Entity\WorkerRunUsage u WHERE u.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Bridge\Entity\WorkerRunFact f WHERE f.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Bridge\Entity\ExperimentPin p WHERE p.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Bridge\Entity\ExperimentDefinition d WHERE d.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
