<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** One series of the chart and its colour. */
final readonly class MetricChartSeries
{
    public function __construct(
        /** Null for the rows with no group. */
        public ?string $group,
        /** The palette slot, from 1. Zero for the grey that the series past the seventh of more than eight share. */
        public int $slot,
        /** The place of the series in the query view, which the template reads its label by. */
        public int $position,
    ) {
    }
}
