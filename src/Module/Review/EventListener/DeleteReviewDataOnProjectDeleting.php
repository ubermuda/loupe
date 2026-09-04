<?php

declare(strict_types=1);

namespace App\Module\Review\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bulk-deletes the Review-module subtree of a project in FK order:
 * reviews / comments / highlights -> document_versions -> document_tags /
 * document_references / decision_selections -> documents -> tags. No entity
 * hydration; runs inside ProjectDeleter's transaction.
 *
 * The order is two independent chains, not one list: highlights, comments and
 * reviews hang off document_versions and so precede it, while tag join rows,
 * references and decision selections hang off documents and so precede that. A
 * table added to the wrong chain still reads plausibly here and fails only at
 * runtime.
 *
 * `tags` and `series` are neither chain and come last: the documents reference
 * them, so they cannot precede the document delete, and they hang off the
 * project rather than any document.
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
        $projectId = (string) ($event->project->id ?? throw new \LogicException('a persisted project always has an id'));

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

        // Native SQL, not DQL: a many-to-many join table has no entity to name.
        $this->em->getConnection()->executeStatement(
            'DELETE FROM document_tags WHERE document_id IN (SELECT id FROM documents WHERE project_id = :project)',
            ['project' => $projectId],
        );

        // Native SQL for the same reason. Matching either end keeps this correct
        // for a reference whose two documents ever stop sharing a project.
        $this->em->getConnection()->executeStatement(
            'DELETE FROM document_references WHERE source_document_id IN (SELECT id FROM documents WHERE project_id = :project)
                OR target_document_id IN (SELECT id FROM documents WHERE project_id = :project)',
            ['project' => $projectId],
        );

        // Second chain, with the tag and reference rows: a selection hangs off
        // documents, not versions, so it only has to precede the delete below.
        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\DecisionSelection s WHERE s.document IN (SELECT sd.id FROM App\Module\Review\Entity\Document sd WHERE sd.project = :project)',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\Document d WHERE d.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\Tag t WHERE t.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Review\Entity\Series s2 WHERE s2.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
