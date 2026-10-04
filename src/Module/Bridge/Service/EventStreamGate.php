<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Repository\BridgeRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Admits to the events endpoints only a bridge of the caller that runs work
 * requests. The bridge reports its capabilities in its heartbeat, so a new
 * CLI sends one before it asks for the events.
 */
final readonly class EventStreamGate
{
    public const string HEADER = 'X-Loupe-Bridge';

    public function __construct(
        private BridgeRepository $bridges,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws BridgeUpgradeRequired */
    public function admit(User $user, ?string $bridgeId): void
    {
        $bridge = null !== $bridgeId && Uuid::isValid($bridgeId)
            ? $this->bridges->findOneByOwnerAndId($user, Uuid::fromString($bridgeId))
            : null;

        if (null !== $bridge && $bridge->takesWorkRequests()) {
            return;
        }

        [$reason, $message] = match (true) {
            null === $bridgeId || !Uuid::isValid($bridgeId) => ['no_bridge_id', 'This Loupe server sends events only to a bridge that runs work requests. Upgrade the loupe CLI.'],
            null === $bridge => ['unknown_bridge', 'This Loupe server has no heartbeat from this bridge. Upgrade the loupe CLI, which sends its heartbeat before it reads the events.'],
            default => ['no_work_requests', 'This bridge runs no work requests. Upgrade the loupe CLI and map the work kinds under work: in rules.yaml.'],
        };

        $this->logger->info('bridge.events_refused', [
            'userId' => (string) $user->id,
            'bridgeId' => null === $bridgeId ? null : substr($bridgeId, 0, 64),
            'reason' => $reason,
        ]);

        throw new BridgeUpgradeRequired($message);
    }
}
