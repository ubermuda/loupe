<?php

declare(strict_types=1);

namespace App\Outbox\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Bulk-deletes a project's outbox rows inside ProjectDeleter's transaction. */
#[AsEventListener]
final readonly class DeleteOutboxEventsOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery(
            'DELETE App\Outbox\Entity\OutboxEvent e WHERE e.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
