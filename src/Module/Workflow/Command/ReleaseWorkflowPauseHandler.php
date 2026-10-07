<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Command\ReleaseCardPauseCommand;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\WorkflowAutomation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ends a workflow pause and re-arms its rule with a fresh budget, so the next evaluation fires it.
 * The card lock the engine takes keeps an evaluation from running between the release and the re-arm.
 */
final readonly class ReleaseWorkflowPauseHandler
{
    public const string CARD_UNMANAGED = 'workflow.pause_release.error.card_unmanaged';

    public const string NOT_PAUSED = 'workflow.pause_release.error.not_paused';

    public const string PAUSE_CHANGED = 'workflow.pause_release.error.pause_changed';

    public const string KIND_NOT_RELEASABLE = 'workflow.pause_release.error.kind_not_releasable';

    public function __construct(
        private EntityManagerInterface $em,
        private WorkflowRuleStateRepository $workflowRuleStates,
        private CardHolds $cardHolds,
        private WorkflowAutomation $automation,
        private CardPauseRepository $cardPauses,
        private ReleaseCardPauseHandler $releaseCardPause,
        private CardEventRepository $cardEvents,
        private CardEvaluations $evaluations,
    ) {
    }

    public function __invoke(ReleaseWorkflowPauseCommand $command): ReleaseWorkflowPauseView
    {
        $card = $command->card;
        $cardId = $card->id ?? throw new \LogicException('A persisted card has an id.');

        // Returns the refusal, because an exception inside the closure closes the entity manager.
        $outcome = $this->em->wrapInTransaction(function () use ($command, $card, $cardId): CardPause|string {
            $this->workflowRuleStates->lockCard($cardId);
            if ($this->cardHolds->isHeld($card->project, $cardId) || !$this->automation->runsFor($card->project)) {
                return self::CARD_UNMANAGED;
            }
            $pause = $this->cardPauses->findActiveForCard($card);
            if (null === $pause) {
                return self::NOT_PAUSED;
            }
            if (null !== $command->pauseId && !$command->pauseId->equals($pause->id)) {
                return self::PAUSE_CHANGED;
            }
            if (!\in_array($pause->kind, ReleaseWorkflowPauseCommand::RELEASABLE_KINDS, true)) {
                return self::KIND_NOT_RELEASABLE;
            }
            if (!($this->releaseCardPause)(new ReleaseCardPauseCommand($pause, ReleaseWorkflowPauseCommand::REASON))) {
                return self::NOT_PAUSED;
            }

            $state = $this->workflowRuleStates->findForCard($card)[$pause->ruleId] ?? null;
            if (null !== $state) {
                $state->truth = false;
                $state->attempts = 0;
                $state->fires = 0;
                $state->dueAt = null;
                $state->lastRefusal = null;
                $state->lastRefusalAt = null;
                $state->workRequestId = null;
                $state->repaired = false;
                $state->updatedAt = $pause->releasedAt ?? throw new \LogicException('A released pause has a release time.');
            }
            $this->cardEvents->record($card, CardEventKind::PauseReleased, $command->actorKind, $command->actor, [
                'kind' => $pause->kind->value,
                'reason' => $pause->reason,
                'ruleId' => $pause->ruleId,
            ], $pause->releasedAt);
            $this->em->flush();
            $this->evaluations->forCards([$cardId]);

            return $pause;
        });

        if (\is_string($outcome)) {
            throw new DomainErrors(['pause' => $outcome]);
        }

        return new ReleaseWorkflowPauseView($outcome->kind, $outcome->reason, $outcome->ruleId);
    }
}
