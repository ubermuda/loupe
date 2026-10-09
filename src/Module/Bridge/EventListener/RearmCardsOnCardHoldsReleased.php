<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Event\CardHoldsReleased;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Contract\WorkflowRuleStates;
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
        private WorkRequestRepository $workRequests,
        private WorkflowRuleStates $ruleStates,
        private CardEvaluations $evaluations,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CardHoldsReleased $event): void
    {
        $now = $this->clock->now();
        $this->workRequests->restartClockOfOpenForCards($event->projectId, $event->cardIds, $now);
        $this->ruleStates->rearmCards($event->cardIds, $now);
        $this->evaluations->forCards($event->cardIds);
    }
}
