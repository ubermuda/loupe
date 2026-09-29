<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Stores whether a person wants the bridge to start no new work. The heartbeat
 * reply carries the value to the bridge. A request that changes nothing keeps
 * the time and the person of the last change.
 */
final readonly class SetBridgePauseHandler
{
    public const string UNKNOWN_BRIDGE = 'bridge.pause.error.unknown_bridge';

    public function __construct(
        private BridgeRepository $bridges,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SetBridgePauseCommand $command): Bridge
    {
        $ownerId = (string) ($command->owner->id ?? throw new \LogicException('A bridge owner always has an id.'));

        [$bridge, $changed] = $this->em->wrapInTransaction(function () use ($command, $ownerId): array {
            // The same lock the heartbeat takes, so a heartbeat cannot replace the row between the read and the write.
            $this->bridges->lockForWrite($ownerId, $command->bridgeId);

            $bridge = $this->bridges->findOneByOwnerAndId($command->owner, $command->bridgeId);
            if (null === $bridge || $bridge->pauseRequested === $command->paused) {
                return [$bridge, false];
            }

            $bridge->pauseRequested = $command->paused;
            $bridge->pauseRequestedAt = $this->clock->now();
            $bridge->pauseRequestedBy = $command->requestedBy;
            $this->em->flush();

            return [$bridge, true];
        });

        if (!$bridge instanceof Bridge) {
            throw new DomainErrors(['bridge' => self::UNKNOWN_BRIDGE]);
        }

        if ($changed) {
            $this->auditor->record(
                'bridge.pause_changed',
                AuditOutcome::Success,
                ['bridgeId' => (string) $bridge->id, 'paused' => $bridge->pauseRequested],
                new AuditSubject('bridge', (string) $bridge->id),
            );
        }

        return $bridge;
    }
}
