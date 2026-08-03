<?php

declare(strict_types=1);

namespace App\Module\Review\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bulk-deletes the Review-module subtree of a project in FK order:
 * reviews / comments -> document_tags -> document_versions -> documents -> tags.
 * No entity hydration; runs inside ProjectDeleter's transaction.
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

        // Native SQL, not DQL: a many-to-many join table has no entity to name in
        // a DQL DELETE, and the documents rows it references are about to go.
        $this->em->getConnection()->executeStatement(
            'DELETE FROM document_tags WHERE document_id IN (SELECT id FROM documents WHERE project_id = :project)',
            ['project' => (string) ($event->project->id ?? throw new \LogicException('a persisted project always has an id'))],
        );

        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\DocumentVersion v WHERE v.document IN (SELECT d.id FROM App\Module\Review\Entity\Document d WHERE d.project = :project)',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\Document d WHERE d.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\Tag t WHERE t.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
