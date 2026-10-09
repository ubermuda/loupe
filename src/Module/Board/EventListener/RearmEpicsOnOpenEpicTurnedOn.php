<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Contract\WorkflowRefusal;
use App\Module\Workflow\Contract\WorkflowRuleStates;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** No fact changes when the open write turns on, so an epic that waits on it would retry late or stay paused. */
#[AsEventListener]
final readonly class RearmEpicsOnOpenEpicTurnedOn
{
    public const string REASON = 'open-epic-turned-on';

    public function __construct(
        private BoardAutomation $automation,
        private WorkflowRuleStates $ruleStates,
        private CardEvaluations $evaluations,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BoardAutomationSettingsSaved $event): void
    {
        if (!$event->openEpicTurnedOn || $event->turnedOn || !$this->evaluations->isOn() || !$this->automation->settingsOf($event->project)->enabled) {
            return;
        }

        $projectId = $event->project->id ?? throw new \LogicException('A stored project has an id.');
        $cardIds = $this->ruleStates->rearmRefused($projectId, WorkflowRefusal::OPEN_EPIC_OFF, self::REASON);
        if ([] === $cardIds) {
            return;
        }

        $this->evaluations->forCards($cardIds);
        $this->logger->info('workflow.open_epic_rearmed', [
            'projectId' => (string) $projectId,
            'cardIds' => array_map(strval(...), $cardIds),
        ]);
    }
}
