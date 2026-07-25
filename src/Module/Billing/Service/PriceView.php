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

    /**
     * The amount in major units. Stripe's minor unit is not always a hundredth:
     * JPY has no decimal places and BHD has three, so dividing by 100 would
     * quote a price the customer is not charged. The currency's own exponent is
     * read from ICU.
     */
    public function amountInMajorUnits(): float
    {
        $formatter = new \NumberFormatter('en', \NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, strtoupper($this->currency));
        $fractionDigits = $formatter->getAttribute(\NumberFormatter::FRACTION_DIGITS);

        return $this->unitAmount / 10 ** (is_int($fractionDigits) && $fractionDigits >= 0 ? $fractionDigits : 2);
    }
}
