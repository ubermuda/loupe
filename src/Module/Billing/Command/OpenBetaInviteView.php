<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

final readonly class OpenBetaInviteView
{
    public function __construct(
        public BetaInviteOutcome $outcome,
        /** The tester still pays through Stripe, which the comp does not stop. */
        public bool $hasLiveSubscription = false,
    ) {
    }
}
