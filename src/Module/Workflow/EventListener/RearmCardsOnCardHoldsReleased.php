<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Bridge\Event\CardHoldsReleased;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Workflow\Repository\WorkflowPendingBaselineRepository;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A card that is managed again fires each rule that matches it now, with a fresh work budget. Its open requests get a new timeout.
 * A baseline mark written while it was held goes, so the mark does not make that pass quiet.
 */
#[AsEventListener]
final readonly class RearmCardsOnCardHoldsReleased
{
    public function __construct(
        private WorkflowRuleStateRepository $workflowRuleStates,
        private WorkRequestRepository $workRequests,
        private WorkflowPendingBaselineRepository $workflowPendingBaselines,
        private EvaluationTrigger $trigger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CardHoldsReleased $event): void
    {
        $now = $this->clock->now();
        $this->workflowRuleStates->resetForCards($event->cardIds, $now);
        $this->workRequests->restartClockOfOpenForCards($event->projectId, $event->cardIds, $now);
        $this->workflowPendingBaselines->unmarkCards($event->cardIds);
        $this->trigger->forCards($event->cardIds);
    }
}
