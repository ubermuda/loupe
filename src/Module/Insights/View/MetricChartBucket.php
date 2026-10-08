<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** One bucket of the time axis and the bars of its series, side by side. */
final readonly class MetricChartBucket
{
    /** @param list<MetricChartBar> $bars in series order, with no bar for a series with no known value */
    public function __construct(
        public \DateTimeImmutable $start,
        public float $centre,
        public array $bars,
    ) {
    }
}
