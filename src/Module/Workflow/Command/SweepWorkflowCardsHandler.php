<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Service\EvaluationTrigger;

/** The backstop for a card change that no event reported. Answers how many evaluations it queued. */
final readonly class SweepWorkflowCardsHandler
{
    public function __construct(
        private WorkflowBindingRepository $workflowBindings,
        private EvaluationTrigger $trigger,
    ) {
    }

    public function __invoke(SweepWorkflowCardsCommand $command): int
    {
        $cardIds = $this->workflowBindings->findOpenBoundCardIds();
        $this->trigger->forCards($cardIds);

        return \count($cardIds);
    }
}
