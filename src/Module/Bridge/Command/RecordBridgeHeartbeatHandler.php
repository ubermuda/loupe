<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Creates or replaces the owner's row for one bridge. Null when another account
 * holds the bridge id, and the row is then left as it was.
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

    public function __invoke(RecordBridgeHeartbeatCommand $command): ?Bridge
    {
        $ownerId = (string) ($command->owner->id ?? throw new \LogicException('An authenticated user always has an id.'));
        $projects = $this->ownedProjects($command->owner, $command->projects);

        // Keyed on the bridge id, not the owner, so two first heartbeats of one
        // id from two accounts also queue, rather than both missing the read and
        // one tripping the primary key.
        [$bridge, $created] = $this->em->wrapInTransaction(function () use ($command, $ownerId, $projects): array {
            $this->em->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                ['bridge:'.$command->bridgeId->toRfc4122()],
            );

            $now = $this->clock->now();
            $bridge = $this->bridges->find($command->bridgeId);
            if (null === $bridge) {
                $bridge = new Bridge($command->bridgeId, $command->owner, $projects, $command->cliVersion, $now);
                $this->em->persist($bridge);
                $this->em->flush();

                return [$bridge, true];
            }

            if ((string) $bridge->owner->id !== $ownerId) {
                return [null, false];
            }

            $bridge->projects = $projects;
            $bridge->cliVersion = $command->cliVersion;
            $bridge->lastSeenAt = $now;
            $this->em->flush();

            return [$bridge, false];
        });

        // A heartbeat that replaces the row is routine traffic, once a minute per
        // bridge, so only the first one and a refusal reach the audit trail.
        if (null === $bridge) {
            $this->auditor->record(
                'bridge.heartbeat_refused',
                AuditOutcome::Refused,
                ['bridgeId' => (string) $command->bridgeId, 'reason' => 'bridge_held_by_another_account'],
                new AuditSubject('bridge', (string) $command->bridgeId),
            );
        } elseif ($created) {
            $this->auditor->record(
                'bridge.bridge_registered',
                AuditOutcome::Success,
                ['bridgeId' => (string) $bridge->id, 'projects' => \count($bridge->projects), 'cliVersion' => $bridge->cliVersion],
                new AuditSubject('bridge', (string) $bridge->id),
            );
        }

        return $bridge;
    }

    /**
     * The ids the owner holds, in the order the bridge sent them. Another
     * account's project reads the same as a project that does not exist.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function ownedProjects(User $owner, array $ids): array
    {
        $owned = array_map(
            static fn (Project $project): string => (string) $project->id,
            $this->projects->findByOwner($owner),
        );

        return array_values(array_filter($ids, static fn (string $id): bool => \in_array($id, $owned, true)));
    }
}
