<?php

declare(strict_types=1);

namespace App\Module\Bridge\Scheduler;

use App\Module\Bridge\Command\ReopenLapsedWorkRequestsCommand;
use App\Module\Bridge\Command\ReopenLapsedWorkRequestsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Opens again the work requests whose claim lease ran out, every minute. */
#[AsCronTask('%app.bridge.work_request_reopen_schedule%')]
final readonly class ReopenLapsedWorkRequestsTask
{
    public function __construct(
        private ReopenLapsedWorkRequestsHandler $reopenLapsedWorkRequests,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $reopened = ($this->reopenLapsedWorkRequests)(new ReopenLapsedWorkRequestsCommand());

        // A tick that finds nothing is the common case, and a line per minute would bury the worker log.
        if ($reopened > 0) {
            $this->logger->info('bridge.work_requests_reopened', ['reopened' => $reopened]);
        }
    }
}
