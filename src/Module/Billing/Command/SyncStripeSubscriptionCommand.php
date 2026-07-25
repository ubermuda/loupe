<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

/**
 * Scalars only: the payload comes from an external system, so the command
 * carries the identifiers and the handler resolves our own entities.
 */
final readonly class SyncStripeSubscriptionCommand
{
    public function __construct(
        /** @phpstan-var non-empty-string */
        public string $stripeEventId,
        /** @phpstan-var non-empty-string */
        public string $stripeCustomerId,
        /** @phpstan-var non-empty-string */
        public string $stripeSubscriptionId,
        public string $stripeStatus,
        public ?\DateTimeImmutable $currentPeriodEnd,
        public \DateTimeImmutable $eventCreatedAt,
    ) {
    }
}
