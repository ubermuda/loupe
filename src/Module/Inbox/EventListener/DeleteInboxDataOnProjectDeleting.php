<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bulk-deletes the inbox rows of a project in foreign key order, inside
 * ProjectDeleter's transaction. The card and document links also cascade from
 * Board and Review, so no listener order leaves a row behind.
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
            'DELETE App\Module\Inbox\Entity\InboxAskItem l WHERE l.ask IN (SELECT a.id FROM App\Module\Inbox\Entity\InboxAsk a WHERE a.project = :project)',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Inbox\Entity\InboxAsk a WHERE a.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Inbox\Entity\InboxItemCard l WHERE l.item IN (SELECT i.id FROM App\Module\Inbox\Entity\InboxItem i WHERE i.project = :project)',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Inbox\Entity\InboxItemDocument l WHERE l.item IN (SELECT i.id FROM App\Module\Inbox\Entity\InboxItem i WHERE i.project = :project)',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Inbox\Entity\InboxItem i WHERE i.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
