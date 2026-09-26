<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

/** One axis label: a dollar amount on the value axis, or a day on the time axis. */
final readonly class CostChartTick
{
    public function __construct(
        public float $position,
        /** Dollars on the value axis. */
        public float $amount = 0.0,
        public ?\DateTimeImmutable $day = null,
    ) {
    }
}
