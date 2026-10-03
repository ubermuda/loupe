<?php

declare(strict_types=1);

namespace App\Module\Workflow\Scheduler;

use App\Module\Workflow\Command\EvaluateDueWorkflowCardsCommand;
use App\Module\Workflow\Command\EvaluateDueWorkflowCardsHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Queues an evaluation of the cards whose refused rule is due to retry, or whose timed condition is due.
 * `app:evaluate-due-workflow-cards` is the manual backstop.
 */
#[AsCronTask('%app.workflow.evaluate_due_schedule%')]
final readonly class EvaluateDueCardsTask
{
    public function __construct(
        private EvaluateDueWorkflowCardsHandler $evaluateDueWorkflowCards,
    ) {
    }

    public function __invoke(): void
    {
        ($this->evaluateDueWorkflowCards)(new EvaluateDueWorkflowCardsCommand());
    }
}
