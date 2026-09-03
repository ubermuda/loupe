<?php

declare(strict_types=1);

namespace App\Audit\Scheduler;

use App\Module\Audit\AuditLogPurger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Hourly enforcement of the audit retention window. `audit:purge` is the manual
 * backstop.
 *
 * The schedule lives on this side of the boundary: the audit package ships the
 * purger, and the application decides when it runs. Quarter to keeps it off the
 * trial sweep and the export purge.
 */
#[AsCronTask('45 * * * *')]
final readonly class PurgeAuditLogTask
{
    public function __construct(
        private AuditLogPurger $purger,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        // The scheduler discards a task's own output, so one line per tick is
        // what makes purge liveness greppable in the worker logs.
        $this->logger->info('audit.purge_completed', ['purged' => $this->purger->purge()]);
    }
}
