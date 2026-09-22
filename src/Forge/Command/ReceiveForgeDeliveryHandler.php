<?php

declare(strict_types=1);

namespace App\Forge\Command;

use App\Forge\Event\ForgeDeliveryReceived;
use App\Forge\ForgeAdapters;
use App\Forge\InvalidForgeSignature;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

/**
 * Verifies a forge delivery and announces it.
 *
 * It knows nothing about what a delivery means. It says what a forge said, and
 * whatever cares listens. That is what keeps this package free of every Loupe
 * type.
 */
final readonly class ReceiveForgeDeliveryHandler
{
    public function __construct(
        private ForgeAdapters $adapters,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ReceiveForgeDeliveryCommand $command): ForgeDeliveryOutcome
    {
        $adapter = $this->adapters->forSlug($command->forge);
        if (null === $adapter) {
            return ForgeDeliveryOutcome::UnknownForge;
        }

        try {
            $deliveries = $adapter->translate($command->request);
        } catch (InvalidForgeSignature $e) {
            // No subject and no context: the signature failed, so nothing in
            // the request is trustworthy, including the repository it names.
            $this->auditor->record(
                'forge.delivery_rejected',
                AuditOutcome::Refused,
                category: Auditor::CATEGORY_SECURITY,
            );

            $this->logger->warning('forge.delivery_rejected', [
                'forge' => $command->forge,
                'error' => $e->getMessage(),
            ]);

            return ForgeDeliveryOutcome::InvalidSignature;
        }

        if ([] !== $deliveries) {
            $this->events->dispatch(new ForgeDeliveryReceived($deliveries));
        }

        return ForgeDeliveryOutcome::Received;
    }
}
