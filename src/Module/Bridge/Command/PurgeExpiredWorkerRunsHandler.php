<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\WorkerRunRetentionPolicy;
use Psr\Clock\ClockInterface;

/** Deletes the run rows the retention window no longer covers, and answers how many went. */
final readonly class PurgeExpiredWorkerRunsHandler
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkerRunRetentionPolicy $retention,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PurgeExpiredWorkerRunsCommand $command): int
    {
        $cutoff = $this->clock->now()->sub(new \DateInterval('P'.$this->retention->retentionDays().'D'));

        return $this->workerRuns->deleteReceivedBefore($cutoff);
    }
}
