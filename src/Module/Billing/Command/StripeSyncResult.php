<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Billing\Entity\BillingStatus;

/**
 * What the locked section decided, carried out to the caller so the records
 * are written after the transaction commits rather than inside it.
 */
final readonly class StripeSyncResult
{
    public function __construct(
        public StripeSyncDecision $decision,
        public ?BillingStatus $status = null,
        public bool $reenabled = false,
        public bool $disabledOnCancel = false,
    ) {
    }
}
