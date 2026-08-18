<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

/**
 * A Stripe subscription reduced to the two fields the webhook sync writes.
 */
final readonly class SubscriptionView
{
    public function __construct(
        /** Stripe's own status string, e.g. `active` — not a BillingStatus. */
        public string $status,
        public ?\DateTimeImmutable $currentPeriodEnd,
    ) {
    }
}
