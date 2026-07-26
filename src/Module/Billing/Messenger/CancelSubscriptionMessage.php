<?php

declare(strict_types=1);

namespace App\Module\Billing\Messenger;

/**
 * Dispatched by DeleteAccountHandler only after the account deletion
 * transaction has durably committed, so a rolled-back deletion never touches
 * Stripe. Carries the raw identifiers (not the user or billing profile) —
 * both rows are already gone by the time this is dispatched.
 */
final readonly class CancelSubscriptionMessage
{
    public function __construct(
        public string $stripeSubscriptionId,
        public ?string $stripeCustomerId,
        /** For the reconciliation log if every retry is exhausted — the account row is already gone by then. */
        public string $deletedUserId,
    ) {
    }
}
