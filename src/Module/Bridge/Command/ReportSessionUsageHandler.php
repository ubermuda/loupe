<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\Service\WorkerRunUsageRecorder;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives each started worker run of a session the usage of the process at the
 * same place in the start order. A count that differs, or two runs that start
 * in the same second, write nothing, because no run can then be matched to its
 * process with certainty. The start column holds whole seconds.
 */
final readonly class ReportSessionUsageHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private WorkerRunRepository $workerRuns,
        private WorkerRunUsageRecorder $usageRecorder,
        private EntityManagerInterface $em,
        private WorkerRunChangedPublisher $publisher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReportSessionUsageCommand $command): ReportSessionUsageResult
    {
        /** @var array{ReportSessionUsageResult, Project|null} $outcome */
        $outcome = $this->em->wrapInTransaction(function () use ($command): array {
            // The same lock a state report takes, so the two never write the usage of one run at once.
            $project = $this->lockedProject($command);
            if (null === $project) {
                return [new ReportSessionUsageResult(null, \count($command->processes), 0), null];
            }

            $runs = $this->workerRuns->findStartedOfSessionForUpdate($project, $command->sessionId);
            if (\count($runs) !== \count($command->processes)) {
                return [new ReportSessionUsageResult(\count($runs), \count($command->processes), 0), $project];
            }

            $starts = array_map(static fn (WorkerRun $run): string => $run->startedAt?->format('Y-m-d H:i:s') ?? '', $runs);
            if (\count(array_unique($starts)) !== \count($starts)) {
                return [new ReportSessionUsageResult(\count($runs), \count($command->processes), 0, ambiguousOrder: true), $project];
            }

            $updated = 0;
            foreach ($runs as $i => $run) {
                if ($this->usageRecorder->record($run, $command->processes[$i])) {
                    ++$updated;
                }
            }
            $this->em->flush();

            return [new ReportSessionUsageResult(\count($runs), \count($command->processes), $updated), $project];
        });

        [$result, $project] = $outcome;
        if (null === $project) {
            return $result;
        }

        $context = [
            'projectId' => (string) $project->id,
            'sessionId' => (string) $command->sessionId,
            'runs' => $result->runs,
            'processes' => $result->processes,
            'updated' => $result->updated,
        ];
        if ($result->runs !== $result->processes) {
            $this->logger->info('bridge.session_usage_count_mismatch', $context);

            return $result;
        }

        if ($result->ambiguousOrder) {
            $this->logger->info('bridge.session_usage_ambiguous_order', $context);

            return $result;
        }

        $this->logger->info('bridge.session_usage_reported', $context);
        if ($result->updated > 0) {
            $this->publisher->runsChanged($project);
        }

        return $result;
    }

    private function lockedProject(ReportSessionUsageCommand $command): ?Project
    {
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return null;
        }

        $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

        return $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
    }
}
