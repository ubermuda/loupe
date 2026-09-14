<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\WorkerRunSearchIndexer;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Appends one run row for one of the owner's projects, once. A repeat of a
 * report the server already holds answers with the row it already wrote.
 */
final readonly class ReportWorkerRunHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private WorkerRunRepository $workerRuns,
        private WorkerRunSearchIndexer $searchIndexer,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ReportWorkerRunCommand $command): ReportWorkerRunResult
    {
        // One transaction, so a failed index update never leaves a run that no
        // search can reach. The project lock serialises two reports of one run,
        // which would otherwise both miss the read and trip the unique index.
        $result = $this->em->wrapInTransaction(function () use ($command): ReportWorkerRunResult {
            // Owner-scoped, so another user's project reads as absent. Read
            // again under the lock: a delete in flight holds the row, so the
            // lock waits for it and the second read then finds nothing.
            $project = $this->lockedProject($command);
            if (null === $project) {
                return new ReportWorkerRunResult(null, created: false);
            }

            $existing = $this->workerRuns->findOneByReportKey(
                $project,
                $command->bridgeId,
                $command->cardId,
                $command->startedAt,
            );
            if (null !== $existing) {
                return new ReportWorkerRunResult($existing, created: false);
            }

            $run = new WorkerRun(
                project: $project,
                bridgeId: $command->bridgeId,
                cardId: $command->cardId,
                cardNumber: $command->cardNumber,
                ruleName: $command->ruleName,
                startedAt: $command->startedAt,
                endedAt: $command->endedAt,
                exitCode: $command->exitCode,
                failureReason: $command->failureReason,
                output: $command->output,
                receivedAt: $this->clock->now(),
            );
            $this->em->persist($run);
            $this->em->flush();
            $this->searchIndexer->index($run);

            return new ReportWorkerRunResult($run, created: true);
        });

        if ($result->created && null !== $result->run) {
            $this->auditor->record(
                'bridge.worker_run_recorded',
                AuditOutcome::Success,
                [
                    'projectId' => (string) $result->run->project->id,
                    'bridgeId' => (string) $command->bridgeId,
                    'cardNumber' => $command->cardNumber,
                    'ruleName' => $command->ruleName,
                    'exitCode' => $command->exitCode,
                    'spawnFailed' => null === $command->exitCode,
                ],
                new AuditSubject('worker_run', (string) $result->run->id),
            );
        }

        return $result;
    }

    private function lockedProject(ReportWorkerRunCommand $command): ?Project
    {
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return null;
        }

        $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

        return $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
    }
}
