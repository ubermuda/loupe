<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** One axis label: a value on the value axis, or the start of a bucket on the time axis. */
final readonly class MetricChartTick
{
    public function __construct(
        public float $position,
        public float $value = 0.0,
        public ?\DateTimeImmutable $start = null,
    ) {
    }
}
