<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\WorkerRunSearchIndexer;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Appends one run row for one of the owner's projects. Null when the owner has
 * no project by that handle.
 */
final readonly class ReportWorkerRunHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private WorkerRunSearchIndexer $searchIndexer,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ReportWorkerRunCommand $command): ?WorkerRun
    {
        // The lookup is owner-scoped, so another user's project reads as absent.
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return null;
        }

        // One transaction, so a failed index update never leaves a run that no
        // search can reach.
        $run = $this->em->wrapInTransaction(function () use ($command, $project): WorkerRun {
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

            return $run;
        });

        $this->auditor->record(
            'bridge.worker_run_recorded',
            AuditOutcome::Success,
            [
                'projectId' => (string) $project->id,
                'bridgeId' => (string) $command->bridgeId,
                'cardNumber' => $command->cardNumber,
                'ruleName' => $command->ruleName,
                'exitCode' => $command->exitCode,
                'spawnFailed' => null === $command->exitCode,
            ],
            new AuditSubject('worker_run', (string) $run->id),
        );

        return $run;
    }
}
