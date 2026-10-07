<?php

declare(strict_types=1);

namespace App\Module\Bridge\Scheduler;

use App\Module\Bridge\Command\ExpireSubjectWorkRequestsCommand;
use App\Module\Bridge\Command\ExpireSubjectWorkRequestsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Expires the open requests about a subject other than a card that no bridge
 * took in time, every minute. `app:expire-subject-work-requests` is the manual backstop.
 */
#[AsCronTask('%app.bridge.work_request_reopen_schedule%')]
final readonly class ExpireSubjectWorkRequestsTask
{
    public function __construct(
        private ExpireSubjectWorkRequestsHandler $expireSubjectWorkRequests,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $expired = ($this->expireSubjectWorkRequests)(new ExpireSubjectWorkRequestsCommand());

        // A tick that finds nothing is the common case, and a line per minute would bury the worker log.
        if ($expired > 0) {
            $this->logger->info('bridge.subject_work_requests_expired', ['expired' => $expired]);
        }
    }
}
