<?php

declare(strict_types=1);

namespace App\Module\Review\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bulk-deletes the Review-module subtree of a project in FK order:
 * reviews / comments / decision selections -> document_versions -> documents.
 * DQL bulk deletes, no entity hydration; runs inside ProjectDeleter's
 * transaction.
 */
#[AsEventListener]
final readonly class DeleteReviewDataOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $versionSubselect = 'SELECT v.id FROM App\Module\Review\Entity\DocumentVersion v JOIN v.document vd WHERE vd.project = :project';

        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\Review r WHERE r.version IN ('.$versionSubselect.')',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\Comment c WHERE c.version IN ('.$versionSubselect.')',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\DecisionSelection s WHERE s.document IN (SELECT sd.id FROM App\Module\Review\Entity\Document sd WHERE sd.project = :project)',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\DocumentVersion v WHERE v.document IN (SELECT d.id FROM App\Module\Review\Entity\Document d WHERE d.project = :project)',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\Document d WHERE d.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
