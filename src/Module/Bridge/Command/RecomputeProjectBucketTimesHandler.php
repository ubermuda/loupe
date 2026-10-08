<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerRunToolCallRepository;
use App\Module\Bridge\Service\BucketTimeComputer;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Recomputes the bucket times of every run of the project that still holds
 * tool calls, a chunk of runs at a time. Each chunk takes the project lock,
 * the lock a tool call report takes, so a report never loses to a stale chunk.
 */
final readonly class RecomputeProjectBucketTimesHandler
{
    public const int CHUNK = 200;

    public function __construct(
        private WorkerRunToolCallRepository $workerRunToolCalls,
        private BucketTimeComputer $computer,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecomputeProjectBucketTimesCommand $command): void
    {
        $project = $command->project;
        $after = null;
        $runs = 0;
        do {
            $ids = $this->workerRunToolCalls->findRunIdsWithCallsOfProject($project, $after, self::CHUNK);
            if ([] === $ids) {
                break;
            }

            $this->em->wrapInTransaction(function () use ($project, $ids): void {
                $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
                $this->computer->recompute($project, $ids);
            });
            $after = $ids[\count($ids) - 1];
            $runs += \count($ids);
        } while (self::CHUNK === \count($ids));

        $this->logger->info('bridge.bucket_times_recomputed', ['projectId' => (string) $project->id, 'runs' => $runs]);
    }
}
