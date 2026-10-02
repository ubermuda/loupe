<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\CliCompatibility;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Creates or replaces the owner's row for one bridge. Another account's row
 * under the same bridge id is a different row and stays as it is.
 */
final readonly class RecordBridgeHeartbeatHandler
{
    public function __construct(
        private BridgeRepository $bridges,
        private BridgeCommandRepository $bridgeCommands,
        private ProjectRepository $projects,
        private WorkerRunChangedPublisher $runsChanged,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordBridgeHeartbeatCommand $command): RecordBridgeHeartbeatResult
    {
        $ownerId = (string) ($command->owner->id ?? throw new \LogicException('An authenticated user always has an id.'));
        $owned = $this->projects->findIdsOwnedBy($command->owner, $command->projects);
        $projects = array_values(array_filter($command->projects, static fn (string $id): bool => \in_array($id, $owned, true)));

        // Two first heartbeats of one bridge would otherwise both miss the read
        // and one would trip the primary key.
        [$bridge, $created, $commands, $pauseChanged] = $this->em->wrapInTransaction(function () use ($command, $ownerId, $projects): array {
            $this->bridges->lockForWrite($ownerId, $command->bridgeId);

            $now = $this->clock->now();
            $bridge = $this->bridges->findOneByOwnerAndId($command->owner, $command->bridgeId);
            $created = null === $bridge;
            if (null === $bridge) {
                $bridge = new Bridge($command->owner, $command->bridgeId, $projects, $command->cliVersion, $now);
                $bridge->hooks = $command->hooks ?? [];
                $this->em->persist($bridge);
            } else {
                $bridge->projects = $projects;
                $bridge->cliVersion = $command->cliVersion;
                $bridge->lastSeenAt = $now;
                if (null !== $command->hooks) {
                    $bridge->hooks = $command->hooks;
                }
            }

            $bridge->updateState = $command->updateState;
            $bridge->updateVersion = $command->updateVersion;
            $bridge->installMethod = $command->installMethod;
            if (null !== $command->workerPools) {
                $bridge->workerPools = $command->workerPools;
                $bridge->workerPoolsReportedAt = $now;
            }
            $pauseChanged = null !== $command->paused && $bridge->pausedReported !== $command->paused;
            if (null !== $command->paused) {
                $bridge->pausedReported = $command->paused;
            }
            if (null !== $command->capabilities) {
                $bridge->capabilities = $command->capabilities;
            }
            if ('' === $command->name) {
                $bridge->name = null;
                $bridge->requestedName = null;
            } elseif (null !== $command->name) {
                // Always taken after the bridge lock, so two heartbeats never wait on each other in reverse.
                $this->bridges->lockNamesForWrite($ownerId);
                $bridge->requestedName = $command->name;
                $bridge->name = $this->bridges->isNameHeldByOther($command->owner, $command->bridgeId, $command->name) ? null : $command->name;
            }

            // Read under the lock a new command takes, so the reply misses no command stored before it.
            return [$bridge, $created, $this->bridgeCommands->findPendingFor($command->owner, $command->bridgeId, $now), $pauseChanged];
        });

        if ($pauseChanged) {
            foreach ($this->projects->findOwnedBy($command->owner, $projects) as $project) {
                $this->runsChanged->runsChanged($project);
            }
        }

        // A heartbeat that replaces the row is routine traffic, once a minute per
        // bridge, so only the first one reaches the audit trail.
        if ($created) {
            $this->auditor->record(
                'bridge.bridge_registered',
                AuditOutcome::Success,
                ['bridgeId' => (string) $bridge->id, 'projects' => \count($bridge->projects)],
                new AuditSubject('bridge', (string) $bridge->id),
            );
        }

        return new RecordBridgeHeartbeatResult($bridge, CliCompatibility::RANGE, $commands);
    }
}
