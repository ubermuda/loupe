<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Bulk-deletes the Board-module subtree of a project in FK order: site-review
 * comment links, then pull request links, then cards. No entity hydration; runs
 * inside ProjectDeleter's transaction.
 *
 * The comment links are also cascaded from the comment side, so whichever of
 * this listener and SiteReview's runs first, no row outlives its parents. The
 * explicit delete here covers the card side, which no cascade reaches.
 */
#[AsEventListener]
final readonly class DeleteBoardDataOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery(
            'DELETE App\Module\Board\Entity\CardSiteReviewComment s WHERE s.card IN (SELECT c.id FROM App\Module\Board\Entity\Card c WHERE c.project = :project)',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Board\Entity\CardPullRequest l WHERE l.card IN (SELECT c.id FROM App\Module\Board\Entity\Card c WHERE c.project = :project)',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Board\Entity\Card c WHERE c.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
