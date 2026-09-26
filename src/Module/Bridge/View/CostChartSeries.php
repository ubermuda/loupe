<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

/** One colour of the cost chart: a rule, a model, or the grey of the keys past the first seven of more than eight. */
final readonly class CostChartSeries
{
    public function __construct(
        /** Null for the grey series. */
        public ?string $key,
        /** The palette slot, from 1. Zero for the series of the other keys. */
        public int $slot,
    ) {
    }
}
