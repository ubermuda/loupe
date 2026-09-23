<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Compares the runs a bridge holds with the runs the server thinks are open on
 * it. A run the bridge no longer holds is lost. A timed-out run the bridge
 * still holds reopens in the state the bridge gives. A run the bridge names and
 * the server does not know stays unknown: its own state report creates it.
 */
final readonly class ReportBridgeRunsHandler
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<WorkerRun> the runs whose state changed */
    public function __invoke(ReportBridgeRunsCommand $command): array
    {
        return $this->em->wrapInTransaction(function () use ($command): array {
            $now = $this->clock->now();
            $changed = [];
            foreach ($this->workerRuns->findOpenOrTimedOutOfBridge($command->owner, $command->bridgeId) as $run) {
                $held = $command->runs[$run->runKey?->toRfc4122() ?? ''] ?? null;
                $state = match (true) {
                    null === $held => WorkerRunState::Lost,
                    WorkerRunState::TimedOut === $run->state => $held,
                    default => null,
                };
                if (null === $state) {
                    continue;
                }

                $run->moveTo($state);
                $this->em->persist(new WorkerRunStateChange($run, $state, $now, $now));
                $changed[] = $run;
            }
            $this->em->flush();

            return $changed;
        });
    }
}
