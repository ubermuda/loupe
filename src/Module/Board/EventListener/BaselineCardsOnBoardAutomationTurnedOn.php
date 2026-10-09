<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Workflow\Contract\WorkflowRuleStates;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The engine skipped the cards while the automation was off, so the rules that turned true meanwhile must not fire. */
#[AsEventListener]
final readonly class BaselineCardsOnBoardAutomationTurnedOn
{
    public function __construct(
        private WorkflowRuleStates $ruleStates,
    ) {
    }

    public function __invoke(BoardAutomationSettingsSaved $event): void
    {
        if ($event->turnedOn) {
            $this->ruleStates->baselineProject($event->project->id ?? throw new \LogicException('A stored project has an id.'));
        }
    }
}
