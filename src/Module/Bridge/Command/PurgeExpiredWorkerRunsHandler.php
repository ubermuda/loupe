<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\BridgeHostSampleRepository;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\WorkerRunRetentionPolicy;
use Psr\Clock\ClockInterface;

/**
 * Deletes the run rows, the experiment pins and the host samples the retention
 * window no longer covers, and answers how many runs went.
 */
final readonly class PurgeExpiredWorkerRunsHandler
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private ExperimentPinRepository $experimentPins,
        private BridgeHostSampleRepository $bridgeHostSamples,
        private WorkerRunRetentionPolicy $retention,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PurgeExpiredWorkerRunsCommand $command): int
    {
        $cutoff = $this->clock->now()->sub(new \DateInterval('P'.$this->retention->retentionDays().'D'));

        $this->experimentPins->deleteUpdatedBefore($cutoff);
        $this->bridgeHostSamples->deleteSampledBefore($cutoff);

        return $this->workerRuns->deleteReceivedBefore($cutoff);
    }
}
