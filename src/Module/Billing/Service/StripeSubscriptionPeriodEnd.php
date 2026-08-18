<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use Stripe\StripeObject;

/**
 * current_period_end moved in Stripe's Basil API version: classic payloads
 * carry it on the subscription, newer ones only per item under
 * items.data[].current_period_end. Both shapes are accepted; a payload with
 * neither simply has no known period end.
 */
final readonly class StripeSubscriptionPeriodEnd
{
    public static function from(StripeObject $subscription): ?\DateTimeImmutable
    {
        $raw = $subscription['current_period_end'] ?? null;

        if (!is_int($raw)) {
            $items = $subscription['items'] ?? null;
            $data = $items instanceof StripeObject ? ($items['data'] ?? null) : null;
            $first = is_array($data) ? ($data[0] ?? null) : null;
            $raw = $first instanceof StripeObject ? ($first['current_period_end'] ?? null) : null;
        }

        return is_int($raw) ? new \DateTimeImmutable('@'.$raw) : null;
    }
}
