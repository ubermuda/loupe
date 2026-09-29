<?php

declare(strict_types=1);

namespace App\Module\Inbox\Scheduler;

use App\Module\Inbox\Command\SweepCardWaitsCommand;
use App\Module\Inbox\Command\SweepCardWaitsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Queues a reconcile of the card waits of every project. */
#[AsCronTask('%app.inbox.card_wait_sweep_schedule%')]
final readonly class SweepCardWaitsTask
{
    public function __construct(
        private SweepCardWaitsHandler $sweepCardWaits,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $queued = ($this->sweepCardWaits)(new SweepCardWaitsCommand());

        if ($queued > 0) {
            $this->logger->info('inbox.card_waits_swept', ['queued' => $queued]);
        }
    }
}
