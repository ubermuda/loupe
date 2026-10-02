<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Bulk-deletes the rule states, the slot links, then the binding, of a project inside ProjectDeleter's transaction. */
#[AsEventListener]
final readonly class DeleteWorkflowDataOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery(
            'DELETE App\Module\Workflow\Entity\WorkflowRuleState s WHERE s.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Workflow\Entity\WorkflowSlotLink l WHERE l.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Workflow\Entity\WorkflowBinding b WHERE b.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
