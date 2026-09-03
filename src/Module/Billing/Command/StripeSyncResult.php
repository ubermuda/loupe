<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Billing\Entity\BillingStatus;

/**
 * What the locked section decided, carried out to the caller so the records
 * are written after the transaction commits rather than inside it. The last
 * two are diagnostics for the log line beside the record, and only the locked
 * section can read them.
 */
final readonly class StripeSyncResult
{
    public function __construct(
        public StripeSyncDecision $decision,
        public ?BillingStatus $status = null,
        public bool $reenabled = false,
        public bool $disabledOnCancel = false,
        public ?\DateTimeImmutable $lastStripeEventAt = null,
        public ?string $currentStripeSubscriptionId = null,
    ) {
    }
}
