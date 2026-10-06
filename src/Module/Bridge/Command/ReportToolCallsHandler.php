<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunToolCallRepository;
use App\Module\Bridge\Service\ToolCallCollectionSettings;
use App\Module\Bridge\Service\WorkerRunFactWriter;
use App\Module\Bridge\ValueObject\WorkerRunToolCallReport;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores the tool calls of a run, and the timing when the batch carries it.
 * The calls go in with SQL, which the fact listener never sees, so the handler
 * rewrites the fact row itself.
 */
final readonly class ReportToolCallsHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private WorkerRunRepository $workerRuns,
        private WorkerRunToolCallRepository $workerRunToolCalls,
        private WorkerRunFactWriter $factWriter,
        private ToolCallCollectionSettings $collectionSettings,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReportToolCallsCommand $command): ReportToolCallsResult
    {
        /** @var ReportToolCallsResult $result */
        $result = $this->em->wrapInTransaction(function () use ($command): ReportToolCallsResult {
            // The same lock a state report takes, so the two never write one run at once.
            $project = $this->lockedProject($command);
            if (null === $project) {
                return new ReportToolCallsResult(projectFound: false, runFound: false, stored: 0);
            }

            $run = $this->workerRuns->findOneOfProjectByRunKey($project, $command->runKey);
            if (null === $run) {
                return new ReportToolCallsResult(projectFound: true, runFound: false, stored: 0);
            }

            $calls = $command->calls;
            if (!$this->collectionSettings->collectFullText($project)) {
                $calls = array_map(static fn (WorkerRunToolCallReport $call): WorkerRunToolCallReport => $call->withoutFullText(), $calls);
            }
            $stored = $this->workerRunToolCalls->insertNew($run, $calls);
            if (null !== $command->timing) {
                $run->toolTimeMs = $command->timing->toolTimeMs;
                $run->idleGapMs = $command->timing->idleGapMs;
                $this->em->flush();
            }
            $this->factWriter->upsert([$run->id ?? throw new \LogicException('A found run has an id.')]);

            $this->logger->info('bridge.tool_calls_reported', [
                'projectId' => (string) $project->id,
                'runId' => (string) $run->id,
                'calls' => \count($command->calls),
                'stored' => $stored,
                'timing' => null !== $command->timing,
            ]);

            return new ReportToolCallsResult(projectFound: true, runFound: true, stored: $stored);
        });

        return $result;
    }

    private function lockedProject(ReportToolCallsCommand $command): ?Project
    {
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return null;
        }

        $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

        return $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
    }
}
