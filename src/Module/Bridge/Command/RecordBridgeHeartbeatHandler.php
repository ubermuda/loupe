<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Event\BridgeNameChanged;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\BridgeHostSampleRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\CliCompatibility;
use App\Module\Bridge\Service\HostSampling;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\Service\WorkRequestLease;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Creates or replaces the owner's row for one bridge. Another account's row
 * under the same bridge id is a different row and stays as it is.
 */
final readonly class RecordBridgeHeartbeatHandler
{
    /** The reply repeats the outbox offers, so it needs no more than a bridge can start soon. */
    public const int MAX_WORK_REQUEST_OFFERS = 100;

    public function __construct(
        private BridgeRepository $bridges,
        private BridgeCommandRepository $bridgeCommands,
        private WorkRequestRepository $workRequests,
        private BridgeHostSampleRepository $bridgeHostSamples,
        private HostSampling $hostSampling,
        private WorkRequestLease $lease,
        private ProjectRepository $projects,
        private WorkerRunChangedPublisher $runsChanged,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordBridgeHeartbeatCommand $command): RecordBridgeHeartbeatResult
    {
        $ownerId = (string) ($command->owner->id ?? throw new \LogicException('An authenticated user always has an id.'));
        $owned = $this->projects->findIdsOwnedBy($command->owner, $command->projects);
        $projects = array_values(array_filter($command->projects, static fn (string $id): bool => \in_array($id, $owned, true)));
        // A bridge keeps the flag value it read at connect, so it can still send samples after sampling went off.
        $samples = $this->hostSampling->enabled() ? $command->hostSamples : [];

        // Two first heartbeats of one bridge would otherwise both miss the read
        // and one would trip the primary key.
        [$bridge, $created, $commands, $pauseChanged, $workRequests, $lostClaims, $renamedIn] = $this->em->wrapInTransaction(function () use ($command, $ownerId, $projects, $samples): array {
            $this->bridges->lockForWrite($ownerId, $command->bridgeId);

            $now = $this->clock->now();
            $bridge = $this->bridges->findOneByOwnerAndId($command->owner, $command->bridgeId);
            $created = null === $bridge;
            $followed = $bridge->projects ?? [];
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
            $heldName = $bridge->name;
            if ('' === $command->name) {
                // A release holds the lock until it commits, so a claim waiting on it reads the free name.
                if (null !== $bridge->name) {
                    $this->bridges->lockNamesForWrite($ownerId);
                }
                $bridge->name = null;
                $bridge->requestedName = null;
            } elseif (null !== $command->name) {
                $bridge->requestedName = $command->name;
                // The unique index keeps a held name off every other bridge, so only a new claim needs the check.
                if ($bridge->name !== $command->name) {
                    // Always taken after the bridge lock, so two heartbeats never wait on each other in reverse.
                    $this->bridges->lockNamesForWrite($ownerId);
                    $bridge->name = $this->bridges->isNameHeldByOther($command->owner, $command->bridgeId, $command->name) ? null : $command->name;
                }
            }

            if ([] !== $samples) {
                // The sample rows point at the bridge row, so a new bridge must reach the table first.
                $this->em->flush();
                $this->bridgeHostSamples->insertNew($command->owner->id ?? throw new \LogicException('An authenticated user always has an id.'), $command->bridgeId, $samples);
            }

            $lostClaims = [];
            if (null !== $command->workClaims) {
                $renewed = $this->workRequests->renewLeases($ownerId, $command->bridgeId, $command->workClaims, $this->lease->until($now));
                $named = array_values(array_unique(array_map(static fn (array $claim): string => $claim[0]->toRfc4122(), $command->workClaims)));
                $lostClaims = array_values(array_diff($named, $renewed));
            }
            $workRequests = $bridge->takesWorkRequests()
                ? $this->workRequests->findOpenOffers($bridge->projects, $bridge->capabilities ?? [], self::MAX_WORK_REQUEST_OFFERS)
                : [];

            // Read under the lock a new command takes, so the reply misses no command stored before it.
            return [$bridge, $created, $this->bridgeCommands->findPendingFor($command->owner, $command->bridgeId, $now), $pauseChanged, $workRequests, $lostClaims,
                // A project the bridge stopped following can still hold a notice that names it.
                $heldName === $bridge->name ? [] : array_values(array_unique([...$followed, ...$projects])),
            ];
        });

        if ($pauseChanged) {
            foreach ($this->projects->findOwnedBy($command->owner, $projects) as $project) {
                $this->runsChanged->runsChanged($project);
            }
        }

        if ([] !== $renamedIn) {
            $this->events->dispatch(new BridgeNameChanged($this->projects->findOwnedBy($command->owner, $renamedIn)));
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

        return new RecordBridgeHeartbeatResult($bridge, CliCompatibility::RANGE, $commands, $workRequests, $lostClaims);
    }
}
