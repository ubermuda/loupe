<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Metric\Metric;

final readonly class ListMetricsView
{
    /** @param list<Metric> $metrics */
    public function __construct(
        public array $metrics,
    ) {
    }
}
