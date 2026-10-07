<?php

declare(strict_types=1);

namespace App\Module\Insights\EventListener;

use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Repository\AnalysisRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A bridge claimed the work of a waiting analysis, so the analysis runs. */
#[AsEventListener]
final readonly class StartAnalysisOnWorkRequestClaimed
{
    public function __construct(
        private AnalysisRepository $analyses,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(WorkRequestChanged $event): void
    {
        if (Analysis::SUBJECT_TYPE !== $event->subjectType || WorkRequestState::Claimed !== $event->state) {
            return;
        }

        $this->em->wrapInTransaction(function () use ($event): void {
            $analysis = $this->analyses->findOneLocked($event->subjectId);
            if (null === $analysis || AnalysisState::Waiting !== $analysis->state) {
                return;
            }
            $analysis->start();
            $this->em->flush();
        });
    }
}
