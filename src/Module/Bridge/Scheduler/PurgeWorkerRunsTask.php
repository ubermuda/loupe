<?php

declare(strict_types=1);

namespace App\Module\Bridge\Scheduler;

use App\Module\Bridge\Command\PurgeExpiredWorkerRunsCommand;
use App\Module\Bridge\Command\PurgeExpiredWorkerRunsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Hourly enforcement of the run retention window. `app:purge-worker-runs` is
 * the manual backstop.
 *
 * Cron, not `#[AsPeriodicTask]`: the periodic trigger counts down from worker
 * boot, and `--time-limit` recycles the worker, restarting the countdown.
 */
#[AsCronTask('%app.bridge.run_purge_schedule%')]
final readonly class PurgeWorkerRunsTask
{
    public function __construct(
        private PurgeExpiredWorkerRunsHandler $purgeExpiredWorkerRuns,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        // The scheduler discards a task's own output, so one line per tick is
        // what makes the sweep greppable in the worker logs.
        $this->logger->info('bridge.worker_runs_purged', [
            'purged' => ($this->purgeExpiredWorkerRuns)(new PurgeExpiredWorkerRunsCommand()),
        ]);
    }
}
