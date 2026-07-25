<?php

declare(strict_types=1);

namespace App\Module\Billing\Entity;

enum BillingStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past-due';
    case Canceled = 'canceled';

    /**
     * Stripe's own `trialing` maps to Active deliberately: the trial this app
     * offers is app-managed and never configured on the Stripe side, so a
     * Stripe-side trial can only mean a real subscription exists. Everything
     * unrecognised — `incomplete`, `incomplete_expired`, `canceled`, and any
     * status Stripe adds later — falls through to Canceled, which paywalls.
     */
    public static function fromStripeStatus(string $stripeStatus): self
    {
        return match ($stripeStatus) {
            'active', 'trialing' => self::Active,
            'past_due', 'unpaid' => self::PastDue,
            default => self::Canceled,
        };
    }
}
