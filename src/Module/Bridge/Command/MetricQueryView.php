<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Metric\MetricSeries;

final readonly class MetricQueryView
{
    /** @param list<MetricSeries> $series by group, the series with no group last */
    public function __construct(
        public MetricQueryCommand $query,
        public array $series,
    ) {
    }
}
