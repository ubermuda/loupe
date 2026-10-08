<?php

declare(strict_types=1);

namespace App\Module\Insights\WorkSubject;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlerInterface;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Repository\AnalysisRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Ends an analysis whose work ended without a report. A worker that reported
 * already completed the analysis, so a done request then changes nothing.
 */
final readonly class AnalysisWorkSubjectHandler implements WorkSubjectHandlerInterface
{
    public const string REFUSED = 'refused';
    public const string NO_REPORT = 'no-report';
    public const string NO_BRIDGE_TOOK_WORK = 'no-bridge-took-work';

    public function __construct(
        private AnalysisRepository $analyses,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function subjectType(): string
    {
        return Analysis::SUBJECT_TYPE;
    }

    #[\Override]
    public function onSettled(WorkRequest $request): void
    {
        $reason = match ($request->state) {
            WorkRequestState::Refused => $request->reason ?? self::REFUSED,
            WorkRequestState::Done => self::NO_REPORT,
            default => null,
        };
        if (null === $reason) {
            return;
        }

        $this->update($request, function (Analysis $analysis) use ($reason): bool {
            if ($analysis->state->isFinished()) {
                return false;
            }
            $analysis->fail($reason, $this->clock->now());

            return true;
        });
    }

    #[\Override]
    public function onExpired(WorkRequest $request): void
    {
        $this->update($request, function (Analysis $analysis): bool {
            if ($analysis->state->isFinished()) {
                return false;
            }
            $analysis->pause(self::NO_BRIDGE_TOOK_WORK, $this->clock->now());

            return true;
        });
    }

    /** @param \Closure(Analysis): bool $change answers whether it changed the analysis */
    private function update(WorkRequest $request, \Closure $change): void
    {
        $analysis = $this->em->wrapInTransaction(function () use ($request, $change): ?Analysis {
            $analysis = $this->analyses->findOneLocked($request->subjectId);
            if (null === $analysis || !$change($analysis)) {
                return null;
            }
            $this->em->flush();

            return $analysis;
        });
        if (null === $analysis) {
            return;
        }

        $this->logger->info('insights.analysis_ended', [
            'analysisId' => (string) $analysis->id,
            'projectId' => (string) $analysis->project->id,
            'workRequestId' => (string) $request->id,
            'state' => $analysis->state->value,
            'reason' => $analysis->reason,
        ]);
    }
}
