<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

/** One colour of the cost chart: a rule, a model, or every key past the palette. */
final readonly class CostChartSeries
{
    public function __construct(
        /** Null for the series that holds every key past the palette. */
        public ?string $key,
        /** The palette slot, from 1. Zero for the series of the other keys. */
        public int $slot,
    ) {
    }
}
