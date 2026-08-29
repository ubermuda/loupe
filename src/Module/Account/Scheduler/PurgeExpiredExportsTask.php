<?php

declare(strict_types=1);

namespace App\Module\Account\Scheduler;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use App\Module\Account\Service\ExpiredExportPurger;
use Psr\Log\LoggerInterface;
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
        private AuditContext $auditContext,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $this->auditContext->channel = AuditChannel::Cron;

        // One line per tick makes scheduler liveness greppable in the worker logs.
        $this->logger->info('account.export.purge_completed', ['purged' => $this->purger->purge()]);
    }
}
