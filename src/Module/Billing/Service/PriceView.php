<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

/**
 * A Stripe price reduced to what the subscribe page needs to render it.
 */
final readonly class PriceView
{
    public function __construct(
        public string $priceId,
        /** Amount in minor units (cents). */
        public int $unitAmount,
        /** Lowercase ISO currency code, e.g. `eur`. */
        public string $currency,
        /** Billing interval, e.g. `month`. */
        public string $interval,
    ) {
    }
}
