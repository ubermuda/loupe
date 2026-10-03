<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use Psr\Clock\ClockInterface;

/** Answers how many evaluations it queued. */
final readonly class EvaluateDueWorkflowCardsHandler
{
    public const int BATCH = 500;

    public function __construct(
        private WorkflowRuleStateRepository $workflowRuleStates,
        private EvaluationTrigger $trigger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EvaluateDueWorkflowCardsCommand $command): int
    {
        $cardIds = $this->workflowRuleStates->findDueCardIds($this->clock->now(), self::BATCH);
        $this->trigger->forCards($cardIds);

        return \count($cardIds);
    }
}
