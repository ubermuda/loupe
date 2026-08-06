<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

final readonly class SeedBillingStateView
{
    public function __construct(
        public bool $billingEnabled,
        public ?string $state,
        public ?TrialSweepResult $sweep,
    ) {
    }
}
