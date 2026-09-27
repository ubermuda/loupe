<?php

declare(strict_types=1);

namespace App\Module\Forge\Scheduler;

use App\Module\Forge\Command\SweepForgePullRequestsCommand;
use App\Module\Forge\Command\SweepForgePullRequestsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Queues a refresh of the open pull requests nobody read lately.
 * `app:sweep-forge-pull-requests` is the manual backstop.
 */
#[AsCronTask('%app.forge.sweep_schedule%')]
final readonly class SweepForgePullRequestsTask
{
    public function __construct(
        private SweepForgePullRequestsHandler $sweepForgePullRequests,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $queued = ($this->sweepForgePullRequests)(new SweepForgePullRequestsCommand());

        if ($queued > 0) {
            $this->logger->info('forge.pull_requests_swept', ['queued' => $queued]);
        }
    }
}
