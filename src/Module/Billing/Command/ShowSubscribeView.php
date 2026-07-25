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
        /**
         * Stripe still holds a subscription for this customer — active, or
         * merely unpaid. Starting a second Checkout in that state would create
         * a second subscription and bill the user twice, so this drives the
         * page towards the portal rather than towards Checkout.
         */
        public bool $hasLiveSubscription = false,
        /** The app-managed trial is still running. */
        public bool $trialing = false,
        /** Whole days left in the trial, never negative. */
        public int $trialDaysLeft = 0,
    ) {
    }
}
