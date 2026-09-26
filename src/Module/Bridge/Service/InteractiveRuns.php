<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Opens and closes the runs of interactive Claude Code sessions on a card. A
 * bridge can launch such a session but never holds its run, so only a close
 * from the session, a person or a move of the card ends it.
 *
 * Each write runs in its own transaction, which nests as a savepoint inside a
 * caller's transaction.
 */
final readonly class InteractiveRuns
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkerRunSearchIndexer $searchIndexer,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private WorkerRunChangedPublisher $publisher,
    ) {
    }

    /** The open run of the session on the card, or a new one. */
    public function open(Project $project, Uuid $cardId, int $cardNumber, Uuid $sessionId, string $name, ?Uuid $bridgeId = null): WorkerRun
    {
        return $this->openUnlessFound(
            $project, $cardId, $cardNumber, $sessionId, $name, $bridgeId,
            fn (): ?WorkerRun => $this->workerRuns->findOpenInteractive($project, $cardId, $sessionId),
        )[0];
    }

    /**
     * Records a session that the bridge launched, and whether this call created
     * the run. The bridge gives each launch a new session, so any run the
     * session already has on the card comes back unchanged. A retry that
     * arrives after a move closed the session thus never opens it again.
     *
     * @return array{WorkerRun, bool}
     */
    public function recordLaunch(Project $project, Uuid $cardId, int $cardNumber, Uuid $sessionId, string $name, Uuid $bridgeId): array
    {
        return $this->openUnlessFound(
            $project, $cardId, $cardNumber, $sessionId, $name, $bridgeId,
            fn (): ?WorkerRun => $this->workerRuns->findLatestInteractive($project, $cardId, $sessionId),
        );
    }

    /**
     * @param \Closure(): ?WorkerRun $find the run that stops the open, read under the project lock
     *
     * @return array{WorkerRun, bool}
     */
    private function openUnlessFound(Project $project, Uuid $cardId, int $cardNumber, Uuid $sessionId, string $name, ?Uuid $bridgeId, \Closure $find): array
    {
        if ('' === trim($name) || mb_strlen($name) > WorkerRun::MAX_RULE_NAME_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('An interactive run needs a name of 1 to %d characters.', WorkerRun::MAX_RULE_NAME_LENGTH));
        }

        /** @var array{WorkerRun, bool} $outcome */
        $outcome = $this->em->wrapInTransaction(function () use ($project, $cardId, $cardNumber, $sessionId, $name, $bridgeId, $find): array {
            // The project lock serialises two opens of one session, which would
            // otherwise both miss the read and trip the unique index.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $found = $find();
            if (null !== $found) {
                return [$found, false];
            }

            $now = $this->clock->now();
            $run = new WorkerRun(
                project: $project,
                bridgeId: $bridgeId,
                cardId: $cardId,
                cardNumber: $cardNumber,
                ruleName: $name,
                state: WorkerRunState::Running,
                sessionId: $sessionId,
                startedAt: $now,
                receivedAt: $now,
                kind: WorkerRunKind::Interactive,
            );
            $this->em->persist($run);
            $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::Running, $now, $now));
            $this->em->flush();
            $this->searchIndexer->index($run);

            return [$run, true];
        });

        if ($outcome[1]) {
            $this->publisher->runsChanged($outcome[0]->project);
        }

        return $outcome;
    }

    /**
     * Records a session that the bridge failed to launch, and whether this call
     * created the row. Any run the session already has on the card comes back
     * unchanged: a retry of the report, or a session that started after all.
     *
     * @return array{WorkerRun, bool}
     */
    public function recordLaunchFailure(
        Project $project,
        Uuid $cardId,
        int $cardNumber,
        Uuid $sessionId,
        string $name,
        Uuid $bridgeId,
        string $failureReason,
        \DateTimeImmutable $at,
    ): array {
        /** @var array{WorkerRun, bool} $outcome */
        $outcome = $this->em->wrapInTransaction(function () use ($project, $cardId, $cardNumber, $sessionId, $name, $bridgeId, $failureReason, $at): array {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $existing = $this->workerRuns->findLatestInteractive($project, $cardId, $sessionId);
            if (null !== $existing) {
                return [$existing, false];
            }

            $now = $this->clock->now();
            $run = new WorkerRun(
                project: $project,
                bridgeId: $bridgeId,
                cardId: $cardId,
                cardNumber: $cardNumber,
                ruleName: $name,
                state: WorkerRunState::NotStarted,
                sessionId: $sessionId,
                endedAt: $at,
                failureReason: mb_substr($failureReason, 0, WorkerRun::MAX_FAILURE_REASON_LENGTH),
                receivedAt: $now,
                kind: WorkerRunKind::Interactive,
            );
            $this->em->persist($run);
            $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::NotStarted, $at, $now));
            $this->em->flush();
            $this->searchIndexer->index($run);

            return [$run, true];
        });

        if ($outcome[1]) {
            $this->publisher->runsChanged($outcome[0]->project);
        }

        return $outcome;
    }

    /** Null when the session has no run on the card. A closed run comes back unchanged. */
    public function close(Project $project, Uuid $cardId, Uuid $sessionId): ?WorkerRun
    {
        return $this->closeLocked(
            fn (): ?WorkerRun => $this->workerRuns->findOpenInteractiveForUpdate($project, $cardId, $sessionId),
            fn (): ?WorkerRun => $this->workerRuns->findLatestInteractive($project, $cardId, $sessionId),
        );
    }

    /** Null when the id names no interactive run of the project. */
    public function closeById(Project $project, Uuid $runId): ?WorkerRun
    {
        return $this->closeLocked(
            fn (): ?WorkerRun => $this->workerRuns->findOpenInteractiveByIdForUpdate($project, $runId),
            fn (): ?WorkerRun => $this->workerRuns->findInteractiveById($project, $runId),
        );
    }

    /**
     * A card that moves leaves its sessions behind. Inside a caller's
     * transaction, a rollback still leaves the reload signal queued, and the
     * page then reloads to what it already shows.
     *
     * @param list<Uuid> $cardIds
     */
    public function closeOnMove(Project $project, array $cardIds): void
    {
        if ([] === $cardIds) {
            return;
        }

        /** @var list<WorkerRun> $closed */
        $closed = $this->em->wrapInTransaction(function () use ($project, $cardIds): array {
            $runs = $this->workerRuns->findOpenInteractiveOfCardsForUpdate($project, $cardIds);
            $at = $this->clock->now();
            foreach ($runs as $run) {
                $this->closeRun($run, $at);
            }
            $this->em->flush();

            return $runs;
        });

        if ([] !== $closed) {
            $this->publisher->runsChanged($closed[0]->project);
        }
    }

    public function hasOpenRun(Project $project, Uuid $cardId): bool
    {
        return $this->workerRuns->hasOpenInteractive($project, $cardId);
    }

    /**
     * Doctrine does not refresh a run it already manages, so the locked read
     * filters on the running state, which Postgres checks again under the lock.
     *
     * @param \Closure(): ?WorkerRun $findOpenLocked
     * @param \Closure(): ?WorkerRun $findAny
     */
    private function closeLocked(\Closure $findOpenLocked, \Closure $findAny): ?WorkerRun
    {
        /** @var array{?WorkerRun, bool} $outcome */
        $outcome = $this->em->wrapInTransaction(function () use ($findOpenLocked, $findAny): array {
            $run = $findOpenLocked();
            if (null === $run) {
                $run = $findAny();
                // The locked read found no running row, so a running copy is stale.
                if (WorkerRunState::Running === $run?->state) {
                    $this->em->detach($run);
                    $run = $findAny();
                }

                return [$run, false];
            }

            $this->closeRun($run, $this->clock->now());
            $this->em->flush();

            return [$run, true];
        });

        [$run, $closed] = $outcome;
        if ($closed && null !== $run) {
            $this->publisher->runsChanged($run->project);
        }

        return $run;
    }

    private function closeRun(WorkerRun $run, \DateTimeImmutable $at): void
    {
        $run->moveTo(WorkerRunState::Closed);
        $run->endedAt = $at;
        $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::Closed, $at, $at));
    }
}
