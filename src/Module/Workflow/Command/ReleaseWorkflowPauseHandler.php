<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Exception\DomainErrors;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\PauseView;
use App\Module\Workflow\Contract\WorkLedger;
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
        private WorkLedger $ledger,
        private WorkflowAutomation $automation,
        private CardPauses $cardPauses,
        private ProjectRepository $projects,
        private CardEvaluations $evaluations,
    ) {
    }

    public function __invoke(ReleaseWorkflowPauseCommand $command): PauseView
    {
        $card = $command->card;
        $cardId = $card->id;
        $project = $this->projects->find($card->projectId) ?? throw new \LogicException('A stored card has a project.');

        // Returns the refusal, because an exception inside the closure closes the entity manager.
        $outcome = $this->em->wrapInTransaction(function () use ($command, $cardId, $project): PauseView|string {
            $this->workflowRuleStates->lockCard($cardId);
            if ($this->ledger->isHeld($project->id ?? throw new \LogicException('A stored project has an id.'), $cardId) || !$this->automation->runsFor($project)) {
                return self::CARD_UNMANAGED;
            }
            $pause = $this->cardPauses->findActive($cardId);
            if (null === $pause) {
                return self::NOT_PAUSED;
            }
            if (null !== $command->pauseId && !$command->pauseId->equals($pause->id)) {
                return self::PAUSE_CHANGED;
            }
            if (!\in_array($pause->kind, ReleaseWorkflowPauseCommand::RELEASABLE_KINDS, true)) {
                return self::KIND_NOT_RELEASABLE;
            }
            $released = $this->cardPauses->release($pause, ReleaseWorkflowPauseCommand::REASON);
            if (null === $released) {
                return self::NOT_PAUSED;
            }

            $state = $this->workflowRuleStates->findForCard($cardId)[$pause->ruleId] ?? null;
            if (null !== $state) {
                $state->truth = false;
                $state->attempts = 0;
                $state->fires = 0;
                $state->dueAt = null;
                $state->lastRefusal = null;
                $state->lastRefusalAt = null;
                $state->workRequestId = null;
                $state->repaired = false;
                $state->updatedAt = $released->releasedAt ?? throw new \LogicException('A released pause has a release time.');
            }
            $this->cardPauses->recordReleased($released, $command->actorKind, $command->actorUserId);
            $this->em->flush();
            $this->evaluations->forCards([$cardId]);

            return $released;
        });

        if (\is_string($outcome)) {
            throw new DomainErrors(['pause' => $outcome]);
        }

        return $outcome;
    }
}
