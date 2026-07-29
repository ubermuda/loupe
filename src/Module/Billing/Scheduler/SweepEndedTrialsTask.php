<?php

declare(strict_types=1);

namespace App\Module\Billing\Scheduler;

use App\Module\Billing\Command\RunTrialSweepCommand;
use App\Module\Billing\Command\RunTrialSweepHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Hourly trial-end sweep, run by the worker that consumes the
 * `scheduler_default` transport. `app:sweep-ended-trials` is the manual
 * backstop.
 *
 * A cron trigger, not `every('1 hour')`: the stateless periodic trigger counts
 * down from worker boot, so a worker recycled by --time-limit=3600 restarts the
 * countdown and the tick may never fire. The cron grid is wall-clock — restarts
 * don't move it, and an hour missed while no worker ran is genuinely caught at
 * the next top of hour. The sweep is marker-idempotent, so no lock or stateful
 * cache is configured: a duplicate tick re-selects nothing.
 */
#[AsCronTask('0 * * * *')]
final readonly class SweepEndedTrialsTask
{
    public function __construct(
        private RunTrialSweepHandler $runTrialSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $result = ($this->runTrialSweep)(new RunTrialSweepCommand());

        // One line per tick makes scheduler liveness greppable in the worker logs.
        $this->logger->info('billing.trial_sweep.completed', [
            'disabled' => $result->disabled,
            'churnedSurveys' => $result->churnedSurveys,
            'subscriberSurveys' => $result->subscriberSurveys,
            'cancelSurveys' => $result->cancelSurveys,
            'failed' => $result->failed,
        ]);
    }
}
