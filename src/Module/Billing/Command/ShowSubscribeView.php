<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Service\PriceView;

/**
 * Everything the subscribe page renders. The state flags are resolved once in
 * the handler — against a single "now" — so the template never re-derives them.
 */
final readonly class ShowSubscribeView
{
    public function __construct(
        public ?BillingProfile $profile,
        public ?PriceView $price,
        public bool $billingEnabled,
        /** A paid subscription is in force. */
        public bool $subscribed = false,
        /** The app-managed trial is still running. */
        public bool $trialing = false,
        /** Whole days left in the trial, never negative. */
        public int $trialDaysLeft = 0,
    ) {
    }
}
