<?php

declare(strict_types=1);

namespace App\Module\Review\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bulk-deletes the Review-module subtree of a project in FK order:
 * reviews / comments / highlights -> document_versions -> document_references
 * -> documents. DQL bulk deletes, no entity hydration; runs inside
 * ProjectDeleter's transaction.
 *
 * The order is two independent chains, not one list: highlights, comments and
 * reviews hang off document_versions and so precede it, while references hang
 * off documents and so precede that. A table added to the wrong chain still
 * reads plausibly here and fails only at runtime.
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
            'DELETE App\Module\Review\Entity\Highlight h WHERE h.version IN ('.$versionSubselect.')',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\DocumentVersion v WHERE v.document IN (SELECT d.id FROM App\Module\Review\Entity\Document d WHERE d.project = :project)',
        )->setParameter('project', $event->project)->execute();

        // Native SQL because document_references is a join table: it has no
        // entity, so DQL cannot name it. Matching either end keeps this correct
        // for a reference whose two documents ever stop sharing a project.
        $this->em->getConnection()->executeStatement(
            'DELETE FROM document_references WHERE source_document_id IN (SELECT id FROM documents WHERE project_id = :project)
                OR target_document_id IN (SELECT id FROM documents WHERE project_id = :project)',
            ['project' => (string) ($event->project->id ?? throw new \LogicException('a persisted project always has an id'))],
        );

        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\Document d WHERE d.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
