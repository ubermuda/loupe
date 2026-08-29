<?php

declare(strict_types=1);

namespace App\Module\Billing\Scheduler;

use App\Module\Billing\Command\RunTrialSweepCommand;
use App\Module\Billing\Command\RunTrialSweepHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Hourly trial-end sweep. `app:sweep-ended-trials` is the manual backstop.
 *
 * Cron, not `#[AsPeriodicTask]`: the periodic trigger counts down from worker
 * boot, and `--time-limit=3600` recycles the worker, restarting the countdown.
 * A missed or duplicated tick is harmless — every action is marker-guarded.
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
