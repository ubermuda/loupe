<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bulk-deletes the asks and the items of a project, inside ProjectDeleter's
 * transaction. The foreign keys of the three link tables cascade from both
 * parents, so the database removes the links.
 */
#[AsEventListener]
final readonly class DeleteInboxDataOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery(
            'DELETE App\Module\Inbox\Entity\InboxAsk a WHERE a.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Inbox\Entity\InboxItem i WHERE i.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
