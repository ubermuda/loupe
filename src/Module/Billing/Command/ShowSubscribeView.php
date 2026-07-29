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
        /** The account was disabled (trial or subscription lapsed). */
        public bool $accountDisabled = false,
        /**
         * A disabled account cannot re-enter: the registration cap is full and
         * no valid invite was presented. Drives the waitlist CTA instead of
         * the checkout button.
         */
        public bool $capFull = false,
        /**
         * Sign-up — and with it `/waitlist`, which 404s whenever registration
         * is closed — is reachable. Only meaningful alongside `capFull`, which
         * is what makes the waitlist the page's remaining offer.
         */
        public bool $waitlistOpen = false,
        /**
         * Only ever a token verified against this user's own waitlist entry —
         * an invalid or foreign token is never echoed back into the page.
         */
        public ?string $inviteToken = null,
    ) {
    }
}
