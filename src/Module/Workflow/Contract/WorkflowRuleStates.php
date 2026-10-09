<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Changes the rule states of cards when a module learns that their rules must start again. The engine implements it. */
interface WorkflowRuleStates
{
    /** Marks every card of the project, so the rules that turned true while the automation was off fire no action. */
    public function baselineProject(Uuid $projectId): void;

    /**
     * Resets the budget of each rule of the cards, and drops a baseline mark written while they were held.
     *
     * @param non-empty-list<Uuid> $cardIds
     */
    public function rearmCards(array $cardIds, \DateTimeImmutable $now): void;

    /**
     * Rearms each rule of the project that the action refused with this code: it releases the retry pause, or makes the wait due now.
     * A card with a pending baseline is left alone. Answers the cards whose rule runs again.
     *
     * @return list<Uuid>
     */
    public function rearmRefused(Uuid $projectId, string $refusal, string $releaseReason): array;
}
