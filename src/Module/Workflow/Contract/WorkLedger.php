<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Reads and ends the work that bridges do for cards. Bridge implements it. */
interface WorkLedger
{
    /**
     * The open and claimed work of the card, oldest first.
     *
     * @return list<WorkView>
     */
    public function live(Uuid $cardId): array;

    public function find(Uuid $requestId): ?WorkView;

    /** Answers false when the work is unknown or already settled. */
    public function withdraw(Uuid $requestId, WithdrawKind $kind): bool;

    /** The newest run of the card that resumes or reruns an earlier run, and that a worker ran or runs. */
    public function latestContinuation(Uuid $cardId, ?\DateTimeImmutable $since, ?string $ruleId): ?RunView;

    /** Whether the run is an open worker run on the card, or shares a session with one. */
    public function isOpenWorkerOnCard(string $runId, Uuid $projectId, Uuid $cardId): bool;

    /**
     * Gives the open work of the cards a new timeout.
     *
     * @param non-empty-list<Uuid> $cardIds
     */
    public function restartClock(Uuid $projectId, array $cardIds, \DateTimeImmutable $now): void;

    public function isHeld(Uuid $projectId, Uuid $cardId): bool;
}
