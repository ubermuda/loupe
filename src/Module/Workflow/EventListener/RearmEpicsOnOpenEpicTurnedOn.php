<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Board\Command\ReleaseCardPauseCommand;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Workflow\Action\ForgeWrite;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\WorkflowAutomation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * No fact changes when the open write turns on, so an epic that waits on it would retry late or stay paused.
 * A save that also turns the automation on is left alone, because its baseline would swallow the re-armed rule.
 */
#[AsEventListener]
final readonly class RearmEpicsOnOpenEpicTurnedOn
{
    public const string REASON = 'open-epic-turned-on';

    public function __construct(
        private EntityManagerInterface $em,
        private WorkflowRuleStateRepository $workflowRuleStates,
        private CardPauseRepository $cardPauses,
        private ReleaseCardPauseHandler $releaseCardPause,
        private CardEventRepository $cardEvents,
        private WorkflowAutomation $automation,
        private CardEvaluations $evaluations,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BoardAutomationSettingsSaved $event): void
    {
        if (!$event->openEpicTurnedOn || $event->turnedOn || !$this->evaluations->isOn() || !$this->automation->runsFor($event->project)) {
            return;
        }

        $cardIds = [];
        foreach ($this->workflowRuleStates->findRefusedInProject($event->project, ForgeWrite::OPEN_EPIC_OFF) as $state) {
            if ($this->em->wrapInTransaction(fn (): bool => $this->rearm($state))) {
                $cardIds[] = $state->card->id ?? throw new \LogicException('A persisted card has an id.');
            }
        }
        if ([] === $cardIds) {
            return;
        }

        $this->evaluations->forCards($cardIds);
        $this->logger->info('workflow.open_epic_rearmed', [
            'projectId' => (string) $event->project->id,
            'cardIds' => array_map(strval(...), $cardIds),
        ]);
    }

    /** Answers whether the rule runs again. */
    private function rearm(WorkflowRuleState $state): bool
    {
        $card = $state->card;
        $this->workflowRuleStates->lockCard($card->id ?? throw new \LogicException('A persisted card has an id.'));
        $this->em->refresh($state);
        if (ForgeWrite::OPEN_EPIC_OFF !== $state->lastRefusal) {
            return false;
        }

        $now = $this->clock->now();
        $pause = $this->cardPauses->findActiveForCard($card);
        if (null === $pause) {
            if (0 === $state->attempts) {
                return false;
            }
            $state->dueAt = $now;
            $state->updatedAt = $now;
            $this->em->flush();

            return true;
        }
        if (CardPauseKind::Retries !== $pause->kind || ForgeWrite::OPEN_EPIC_OFF !== $pause->reason || $state->ruleId !== $pause->ruleId
            || !($this->releaseCardPause)(new ReleaseCardPauseCommand($pause, self::REASON))) {
            return false;
        }

        $state->reset();
        $state->updatedAt = $pause->releasedAt ?? $now;
        $this->cardEvents->record($card, CardEventKind::PauseReleased, CardReporter::System, null, [
            'kind' => $pause->kind->value,
            'reason' => $pause->reason,
            'ruleId' => $pause->ruleId,
        ], $pause->releasedAt);
        $this->em->flush();

        return true;
    }
}
