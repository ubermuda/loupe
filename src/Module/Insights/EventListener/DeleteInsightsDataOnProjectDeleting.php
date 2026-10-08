<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Deletes the analyses, the bucket rules and the settings of a project, inside ProjectDeleter's
 * transaction. The foreign key of a proposal cascades from its analysis.
 */
#[AsEventListener]
final readonly class DeleteInsightsDataOnProjectDeleting
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->em->createQuery(
            'DELETE App\Module\Insights\Entity\Analysis a WHERE a.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Insights\Entity\InsightsBucketRule r WHERE r.project = :project',
        )->setParameter('project', $event->project)->execute();

        $this->em->createQuery(
            'DELETE App\Module\Insights\Entity\InsightsProjectSettings s WHERE s.project = :project',
        )->setParameter('project', $event->project)->execute();
    }
}
