<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Workflow\Repository\WorkflowPendingBaselineRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The engine skipped the cards while the automation was off, so the rules that turned true meanwhile must not fire. */
#[AsEventListener]
final readonly class BaselineCardsOnBoardAutomationTurnedOn
{
    public function __construct(
        private WorkflowPendingBaselineRepository $workflowPendingBaselines,
    ) {
    }

    public function __invoke(BoardAutomationSettingsSaved $event): void
    {
        if ($event->turnedOn) {
            $this->workflowPendingBaselines->markProject($event->project->id ?? throw new \LogicException('A stored project has an id.'));
        }
    }
}
