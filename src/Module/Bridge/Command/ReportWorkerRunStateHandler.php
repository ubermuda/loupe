<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Event\WorkerRunQueued;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\Service\WorkerRunSearchIndexer;
use App\Module\Bridge\Service\WorkerRunUsageRecorder;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunTrigger;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Records one state of a run, and moves the run forward only. A state the run
 * has not held leaves a history row even when it does not move the run. A late
 * report still fills a missing session, start and experiment, but outcome
 * data follows the state that owns it. The outcome or the stop that
 * closes the run also writes its usage, through WorkerRunUsageRecorder.
 */
final readonly class ReportWorkerRunStateHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private WorkerRunRepository $workerRuns,
        private WorkerRunStateChangeRepository $workerRunStateChanges,
        private WorkerRunSearchIndexer $searchIndexer,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private ClockInterface $clock,
        private WorkerRunChangedPublisher $publisher,
        private WorkerRunUsageRecorder $usageRecorder,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(ReportWorkerRunStateCommand $command): ReportWorkerRunStateResult
    {
        /** @var array{ReportWorkerRunStateResult, bool, bool, bool, bool} $outcome */
        $outcome = $this->em->wrapInTransaction(function () use ($command): array {
            // The project lock serialises two first reports of one run, which
            // would otherwise both miss the read and trip the unique index.
            $project = $this->lockedProject($command);
            if (null === $project) {
                return [new ReportWorkerRunStateResult(null, newState: false), false, false, false, false];
            }

            $run = $this->workerRuns->findOneByRunKey($project, $command->bridgeId, $command->runKey);
            // Read before the write: a new outcome is the latest of its card.
            $warned = $command->state->isOutcome()
                && null !== $this->workerRuns->findWarningRowOfCard($project, $run->cardId ?? $command->cardId);
            // Read after the run lock, so a reopening never predates a timeout
            // that the sweep wrote while this report waited.
            $receivedAt = $this->clock->now();
            if (null === $run) {
                $run = new WorkerRun(
                    project: $project,
                    bridgeId: $command->bridgeId,
                    cardId: $command->cardId,
                    cardNumber: $command->cardNumber,
                    ruleName: $command->ruleName,
                    state: $command->state,
                    runKey: $command->runKey,
                    receivedAt: $receivedAt,
                    continuesRun: null === $command->continues
                        ? null
                        : $this->workerRuns->findOneByRunKey($project, $command->bridgeId, $command->continues),
                    resumeIndex: $command->resumeIndex,
                    resumeCap: $command->resumeCap,
                    cardColumn: $command->cardColumn,
                    kind: $command->kind,
                );
                if (null !== $command->trigger) {
                    self::recordTrigger($run, $command->trigger);
                }
                $this->em->persist($run);
                $created = true;
                $moves = true;
                $history = [];
            } else {
                $created = false;
                $history = $this->workerRunStateChanges->statesOf($run);
                $moves = self::moves($run->state, $command->state, $history);
            }

            $this->fillStart($run, $command);
            $this->fillExperiment($run, $command);
            // A queued run can move to another pool, so any report, a repeat too, names the current one.
            $poolMoved = null !== $command->workerPool && $command->workerPool !== $run->workerPool;
            if ($poolMoved) {
                $run->workerPool = $command->workerPool;
            }
            // A retry of the state a timed-out run last held is the bridge
            // speaking again, so it reopens the run and says so in the history.
            $repeat = \in_array($command->state, $history, true);
            $reopens = WorkerRunState::TimedOut === $run->state && $moves && $repeat;
            $newState = $reopens || !$repeat;
            $closes = false;
            $warningChanged = false;
            if ($newState) {
                if ($moves) {
                    $this->apply($run, $command);
                    $closes = !$command->state->isOpen();
                    $warningChanged = $command->state->isOutcome() && ($warned || $command->state->isWarning());
                }
                // A retry carries its first time, which precedes the timeout.
                $at = $reopens ? $receivedAt : $command->at;
                $this->em->persist(new WorkerRunStateChange($run, $command->state, $at, $receivedAt));
            }

            $this->em->flush();
            $this->searchIndexer->index($run);

            return [new ReportWorkerRunStateResult($run, $newState), $closes, $warningChanged, $poolMoved, $created];
        });

        [$result, $closes, $warningChanged, $poolMoved, $created] = $outcome;
        // A repeat of a state the run already held changes nothing a page shows, unless it moves the pool.
        if (($result->newState || $poolMoved) && null !== $result->run) {
            $this->publisher->runsChanged($result->run->project);
            foreach (WorkerRunChanged::ofRuns([$result->run]) as $event) {
                $this->events->dispatch($event);
            }
        }
        if ($warningChanged && null !== $result->run) {
            $this->publisher->cardWarningChanged($result->run->project, $result->run->cardId);
        }
        if ($closes && null !== $result->run) {
            $this->audit($result->run);
        }
        if ($created && null !== $result->run && WorkerRunState::Queued === $command->state
            && WorkerRunTrigger::FIX_REQUESTED === $command->trigger?->eventType) {
            $this->events->dispatch(self::queued($result->run, $command->trigger));
        }

        return $result;
    }

    /**
     * Timed-out is only a guess, so a report replaces it, but an open report
     * must reach the rank the run held before, or a late queued shows a run
     * that already ran as waiting. Only a closed report replaces lost.
     *
     * @param list<WorkerRunState> $history
     */
    private static function moves(WorkerRunState $current, WorkerRunState $reported, array $history): bool
    {
        if (WorkerRunState::TimedOut === $current) {
            $reached = array_map(
                static fn (WorkerRunState $state): int => $state->rank(),
                array_filter($history, static fn (WorkerRunState $state): bool => !$state->isInferred()),
            );

            return $reported->rank() >= max([-1, ...$reached]);
        }

        if (WorkerRunState::Lost === $current) {
            return !$reported->isOpen();
        }

        return $current->isOpen() && $reported->rank() > $current->rank();
    }

    private static function recordTrigger(WorkerRun $run, WorkerRunTrigger $trigger): void
    {
        $run->triggerEventType = $trigger->eventType;
        $run->triggerForge = $trigger->forge;
        $run->triggerRepository = $trigger->repository;
        $run->triggerPullRequestNumber = $trigger->pullRequestNumber;
        $run->triggerHeadSha = $trigger->headSha;
        $run->triggerReason = $trigger->reason;
    }

    private static function queued(WorkerRun $run, WorkerRunTrigger $trigger): WorkerRunQueued
    {
        return new WorkerRunQueued(
            projectId: $run->project->id ?? throw new \LogicException('A persisted project has an id.'),
            runId: $run->id ?? throw new \LogicException('A flushed run has an id.'),
            cardId: $run->cardId,
            eventType: $trigger->eventType,
            forge: $trigger->forge,
            repository: $trigger->repository,
            pullRequestNumber: $trigger->pullRequestNumber,
            headSha: $trigger->headSha,
            reason: $trigger->reason,
        );
    }

    private function fillStart(WorkerRun $run, ReportWorkerRunStateCommand $command): void
    {
        // The bridge sends the start of a process that never started, because the old report needs it.
        if (WorkerRunState::NotStarted === $command->state) {
            return;
        }

        if (null === $run->sessionId && null !== $command->sessionId) {
            $run->sessionId = $command->sessionId;
        }

        if (null === $run->startedAt && null !== $command->startedAt) {
            $run->startedAt = $command->startedAt;
        }
    }

    /** Reports can arrive out of order, so the first report that names an experiment sets all four fields. */
    private function fillExperiment(WorkerRun $run, ReportWorkerRunStateCommand $command): void
    {
        if (null !== $run->experiment || null === $command->experiment) {
            return;
        }

        $run->experiment = $command->experiment;
        $run->variant = $command->variant;
        $run->requestedModel = $command->requestedModel;
        $run->switchedFrom = $command->switchedFrom;
    }

    private function apply(WorkerRun $run, ReportWorkerRunStateCommand $command): void
    {
        if (WorkerRunState::Stopped === $command->state) {
            $run->recordStop($command->endedAt ?? $command->at, $command->output);
            if (null !== $command->usage) {
                $this->usageRecorder->record($run, $command->usage);
            }

            return;
        }

        if (!$command->state->isOutcome()) {
            $run->moveTo($command->state);

            return;
        }

        $run->recordOutcome(
            $command->state,
            $command->endedAt ?? throw new \LogicException('An outcome carries its end after validation.'),
            $command->exitCode,
            $command->hasResult,
            $command->failureReason,
            $command->output ?? '',
            $command->resultStatus,
            $command->resultFields,
            $command->resumeSkipped,
        );

        if (null !== $command->usage) {
            $this->usageRecorder->record($run, $command->usage);
        }
    }

    private function audit(WorkerRun $run): void
    {
        $this->auditor->record(
            'bridge.worker_run_recorded',
            AuditOutcome::Success,
            [
                'projectId' => (string) $run->project->id,
                'bridgeId' => (string) $run->bridgeId,
                'runKey' => (string) $run->runKey,
                'sessionId' => null === $run->sessionId ? null : (string) $run->sessionId,
                'cardNumber' => $run->cardNumber,
                'ruleName' => $run->ruleName,
                'state' => $run->state->value,
                'exitCode' => $run->exitCode,
                'hasResult' => $run->hasResult,
                'spawnFailed' => WorkerRunState::NotStarted === $run->state,
                'resultStatus' => $run->resultStatus,
                // The values are worker prose, so the record keeps the names alone.
                'resultFieldNames' => null === $run->resultFields ? null : implode(',', array_keys($run->resultFields)),
                'continuesRunKey' => $run->continuesRun?->runKey?->toRfc4122(),
                'resumeIndex' => $run->resumeIndex,
                'resumeCap' => $run->resumeCap,
                'cardColumn' => $run->cardColumn,
                'resumeSkipped' => $run->resumeSkipped,
            ],
            new AuditSubject('worker_run', (string) $run->id),
        );
    }

    private function lockedProject(ReportWorkerRunStateCommand $command): ?Project
    {
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return null;
        }

        $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

        return $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
    }
}
