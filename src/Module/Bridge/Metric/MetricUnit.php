<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

/** What one row of a metric stands for. */
enum MetricUnit: string
{
    case Run = 'run';
    case Card = 'card';
}
