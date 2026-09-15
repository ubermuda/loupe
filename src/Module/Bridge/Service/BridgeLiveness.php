<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\View\BridgeStatus;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/** Says whether an owner's bridges still send their heartbeat. */
final readonly class BridgeLiveness
{
    /** A judgement, not a measurement: enough missed heartbeats that one slow post does not raise a warning. */
    public const int QUIET_AFTER_INTERVALS = 3;

    public function __construct(
        private BridgeRepository $bridges,
        private HeartbeatInterval $heartbeatInterval,
        private ClockInterface $clock,

        #[Autowire(param: 'app.bridge.default_heartbeat_interval_seconds')]
        private int $defaultInterval,
    ) {
    }

    /**
     * @param list<Uuid> $bridgeIds
     *
     * @return array<string, BridgeStatus> keyed by the RFC 4122 bridge id, one entry per id
     */
    public function forOwner(User $owner, array $bridgeIds): array
    {
        if ([] === $bridgeIds) {
            return [];
        }

        $now = $this->clock->now();
        $lastSeen = [];
        foreach ($this->bridges->findByOwnerAndIds($owner, $bridgeIds) as $bridge) {
            $lastSeen[$bridge->id->toRfc4122()] = $bridge->lastSeenAt;
        }

        // A running bridge keeps the interval it read at its last reconnect, so a lowered flag cannot shorten the wait.
        $quietAfter = self::QUIET_AFTER_INTERVALS * max($this->heartbeatInterval->seconds(), $this->defaultInterval);
        $statuses = [];
        foreach ($bridgeIds as $bridgeId) {
            $seenAt = $lastSeen[$bridgeId->toRfc4122()] ?? null;
            $statuses[$bridgeId->toRfc4122()] = new BridgeStatus(
                lastSeenAt: $seenAt,
                checkedAt: $now,
                quiet: null === $seenAt || $now->getTimestamp() - $seenAt->getTimestamp() > $quietAfter,
            );
        }

        return $statuses;
    }
}
