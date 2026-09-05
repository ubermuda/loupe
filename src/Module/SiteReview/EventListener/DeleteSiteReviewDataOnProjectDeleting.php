<?php

declare(strict_types=1);

namespace App\Module\SiteReview\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bulk-deletes the SiteReview-module subtree of a project: site_review_events,
 * site_review_comment_anchors, site_review_comments. Runs inside
 * ProjectDeleter's transaction.
 *
 * A DQL DELETE runs no ORM cascade, so the anchors go first and by hand.
 */
#[AsEventListener]
final readonly class DeleteSiteReviewDataOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery(
            'DELETE App\Module\SiteReview\Entity\SiteReviewEvent e WHERE e.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\SiteReview\Entity\SiteReviewCommentAnchor a WHERE a.comment IN ('
            .'SELECT c2.id FROM App\Module\SiteReview\Entity\SiteReviewComment c2 WHERE c2.project = :project)',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\SiteReview\Entity\SiteReviewComment c WHERE c.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
