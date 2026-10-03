<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Bridge\Event\CardHoldsReleased;
use App\Module\Workflow\Engine\EngineSwitch;
use App\Module\Workflow\Repository\WorkflowPendingBaselineRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A card that is managed again must not fire the rules that turned true while it was held.
 * The mark commits with the release, so the first evaluation after it baselines the card, whatever queued it.
 */
#[AsEventListener]
final readonly class BaselineCardsOnCardHoldsReleased
{
    public function __construct(
        private EngineSwitch $engine,
        private WorkflowPendingBaselineRepository $workflowPendingBaselines,
        private EvaluationTrigger $trigger,
    ) {
    }

    public function __invoke(CardHoldsReleased $event): void
    {
        if (!$this->engine->isOn()) {
            return;
        }

        $this->workflowPendingBaselines->markCards($event->projectId, $event->cardIds);
        $this->trigger->forCards($event->cardIds);
    }
}
