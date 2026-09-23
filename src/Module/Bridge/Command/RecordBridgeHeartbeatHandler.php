<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeRepository;
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
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordBridgeHeartbeatCommand $command): Bridge
    {
        $ownerId = (string) ($command->owner->id ?? throw new \LogicException('An authenticated user always has an id.'));
        $owned = $this->projects->findIdsOwnedBy($command->owner, $command->projects);
        $projects = array_values(array_filter($command->projects, static fn (string $id): bool => \in_array($id, $owned, true)));

        // Two first heartbeats of one bridge would otherwise both miss the read
        // and one would trip the primary key.
        [$bridge, $created] = $this->em->wrapInTransaction(function () use ($command, $ownerId, $projects): array {
            $this->bridges->lockForWrite($ownerId, $command->bridgeId);

            $now = $this->clock->now();
            $bridge = $this->bridges->findOneByOwnerAndId($command->owner, $command->bridgeId);
            if (null === $bridge) {
                $bridge = new Bridge($command->owner, $command->bridgeId, $projects, $command->cliVersion, $now);
                $this->em->persist($bridge);

                return [$bridge, true];
            }

            $bridge->projects = $projects;
            $bridge->cliVersion = $command->cliVersion;
            $bridge->lastSeenAt = $now;

            return [$bridge, false];
        });

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

        return $bridge;
    }
}
