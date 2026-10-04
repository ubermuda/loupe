<?php

declare(strict_types=1);

namespace App\Module\Workflow\Scheduler;

use App\Module\Workflow\Command\SweepWorkflowCardsCommand;
use App\Module\Workflow\Command\SweepWorkflowCardsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Queues an evaluation of every open card of every bound project.
 * `app:sweep-workflow-cards` is the manual backstop.
 */
#[AsCronTask('%app.workflow.sweep_schedule%')]
final readonly class SweepWorkflowCardsTask
{
    public function __construct(
        private SweepWorkflowCardsHandler $sweepWorkflowCards,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $started = hrtime(true);
        $cards = ($this->sweepWorkflowCards)(new SweepWorkflowCardsCommand());
        $this->logger->info('workflow.sweep_finished', [
            'cards' => $cards,
            'durationMs' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);
    }
}
