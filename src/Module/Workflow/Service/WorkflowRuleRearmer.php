<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\WorkflowRuleStates;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Repository\WorkflowPendingBaselineRepository;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(WorkflowRuleStates::class)]
final readonly class WorkflowRuleRearmer implements WorkflowRuleStates
{
    public function __construct(
        private EntityManagerInterface $em,
        private WorkflowRuleStateRepository $workflowRuleStates,
        private WorkflowPendingBaselineRepository $workflowPendingBaselines,
        private CardDirectory $cards,
        private CardPauses $cardPauses,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function baselineProject(Uuid $projectId): void
    {
        $this->workflowPendingBaselines->markProject($projectId);
    }

    #[\Override]
    public function rearmCards(array $cardIds, \DateTimeImmutable $now): void
    {
        $this->workflowRuleStates->resetForCards($cardIds, $now);
        $this->workflowPendingBaselines->unmarkCards($cardIds);
    }

    #[\Override]
    public function rearmRefused(Uuid $projectId, string $refusal, string $releaseReason): array
    {
        $rearmed = [];
        foreach ($this->workflowRuleStates->findRefusedInProjectId($projectId, $refusal) as $state) {
            if ($this->em->wrapInTransaction(fn (): bool => $this->rearm($state, $refusal, $releaseReason))) {
                $rearmed[] = $state->cardId;
            }
        }

        return $rearmed;
    }

    private function rearm(WorkflowRuleState $state, string $refusal, string $releaseReason): bool
    {
        $cardId = $state->cardId;
        if (null === $this->cards->find($cardId)) {
            return false;
        }
        $this->workflowRuleStates->lockCard($cardId);
        $this->em->refresh($state);
        if ($refusal !== $state->lastRefusal || $this->workflowPendingBaselines->isMarked($cardId)) {
            return false;
        }

        $now = $this->clock->now();
        $pause = $this->cardPauses->findActive($cardId);
        if (null === $pause) {
            if (0 === $state->attempts) {
                return false;
            }
            $state->dueAt = $now;
            $state->updatedAt = $now;
            $this->em->flush();

            return true;
        }

        if (PauseKind::Retries !== $pause->kind || $refusal !== $pause->reason || $state->ruleId !== $pause->ruleId) {
            return false;
        }
        $released = $this->cardPauses->release($pause, $releaseReason);
        if (null === $released) {
            return false;
        }
        $state->reset();
        $state->updatedAt = $released->releasedAt ?? $now;
        $this->cardPauses->recordReleased($released, Actor::System, null);
        $this->em->flush();

        return true;
    }
}
