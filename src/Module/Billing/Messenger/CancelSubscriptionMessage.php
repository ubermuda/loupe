<?php

declare(strict_types=1);

namespace App\Module\Billing\Messenger;

/**
 * Dispatched by DeleteAccountHandler from inside the account deletion
 * transaction. The `async` transport is Doctrine-backed (see
 * MESSENGER_TRANSPORT_DSN), so the enqueued row is written by the same
 * commit as the deletion — a rolled-back deletion rolls this back too, and
 * the worker consuming it can only ever see it once the transaction has
 * durably committed. Carries the raw identifiers (not the user or billing
 * profile) — both rows are already gone by the time this is consumed.
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
