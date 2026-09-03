<?php

declare(strict_types=1);

namespace App\Module\Account\Scheduler;

use App\Module\Account\Service\ExpiredExportPurger;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Hourly purge of expired data-export archives. `app:purge-expired-exports` is
 * the manual backstop.
 *
 * Cron, not `#[AsPeriodicTask]`: the periodic trigger counts down from worker
 * boot, and `--time-limit=3600` recycles the worker, restarting the countdown.
 * Half past keeps it off the trial sweep's slot.
 */
#[AsCronTask('30 * * * *')]
final readonly class PurgeExpiredExportsTask
{
    public function __construct(
        private ExpiredExportPurger $purger,
    ) {
    }

    public function __invoke(): void
    {
        $this->purger->purge();
    }
}
