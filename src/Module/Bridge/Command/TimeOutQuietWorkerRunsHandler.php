<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\BridgeLiveness;
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
        return $this->em->wrapInTransaction(function () use ($ids, $now): array {
            $runs = $this->workerRuns->findOpenByIdsForUpdate($ids);
            foreach ($runs as $run) {
                $run->moveTo(WorkerRunState::TimedOut);
                $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::TimedOut, $now, $now));
            }
            $this->em->flush();

            return $runs;
        });
    }
}
