<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Moves each open run of a quiet bridge to timed-out, with a history row. The
 * bridge rule is the one the agents page shows. A later report from the bridge
 * replaces the guess.
 */
final readonly class TimeOutQuietWorkerRunsHandler
{
    /** Bounds one tick. A sweep that finds more leaves the rest to the next minute. */
    public const int BATCH_SIZE = 500;

    public function __construct(
        private WorkerRunRepository $workerRuns,
        private BridgeLiveness $liveness,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private WorkerRunChangedPublisher $publisher,
    ) {
    }

    /** @return list<WorkerRun> the runs that timed out */
    public function __invoke(TimeOutQuietWorkerRunsCommand $command): array
    {
        $now = $this->clock->now();
        $ids = $this->workerRuns->findIdsOfQuietOpenRuns($this->liveness->quietBefore($now), self::BATCH_SIZE);
        if ([] === $ids) {
            return [];
        }

        // The locked read keeps only the runs still open, so a report that
        // closed a run after the first read wins.
        /** @var list<WorkerRun> $timedOut */
        $timedOut = $this->em->wrapInTransaction(function () use ($ids): array {
            $runs = $this->workerRuns->findOpenByIdsForUpdate($ids);
            // Read after the lock, so the row never predates a report that won it.
            $at = $this->clock->now();
            foreach ($runs as $run) {
                $run->moveTo(WorkerRunState::TimedOut);
                $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::TimedOut, $at, $at));
            }
            $this->em->flush();

            return $runs;
        });

        foreach ($timedOut as $run) {
            $this->publisher->runsChanged($run->project);
        }

        return $timedOut;
    }
}
