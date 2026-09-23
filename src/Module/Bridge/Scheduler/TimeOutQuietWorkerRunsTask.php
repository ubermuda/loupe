<?php

declare(strict_types=1);

namespace App\Module\Bridge\Scheduler;

use App\Module\Bridge\Command\TimeOutQuietWorkerRunsCommand;
use App\Module\Bridge\Command\TimeOutQuietWorkerRunsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Times out the open runs of quiet bridges every minute.
 * `app:time-out-worker-runs` is the manual backstop.
 */
#[AsCronTask('%app.bridge.run_timeout_schedule%')]
final readonly class TimeOutQuietWorkerRunsTask
{
    public function __construct(
        private TimeOutQuietWorkerRunsHandler $timeOutQuietWorkerRuns,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $timedOut = \count(($this->timeOutQuietWorkerRuns)(new TimeOutQuietWorkerRunsCommand()));

        // A tick that finds nothing is the common case, and a line per minute would bury the worker log.
        if ($timedOut > 0) {
            $this->logger->info('bridge.worker_runs_timed_out', ['timedOut' => $timedOut]);
        }
    }
}
