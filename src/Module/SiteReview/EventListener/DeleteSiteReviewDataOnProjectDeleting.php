<?php

declare(strict_types=1);

namespace App\Module\SiteReview\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bulk-deletes the SiteReview-module subtree of a project in FK order:
 * site_review_comments -> site_review_reviews. Runs inside ProjectDeleter's
 * transaction.
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
            'DELETE App\Module\SiteReview\Entity\SiteReviewComment c WHERE c.review IN (SELECT r.id FROM App\Module\SiteReview\Entity\SiteReview r WHERE r.project = :project)',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\SiteReview\Entity\SiteReview r WHERE r.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
