<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

enum MetricValueType: string
{
    case Money = 'money';
    case Tokens = 'tokens';
    case Duration = 'duration';
    case Count = 'count';
    case Ratio = 'ratio';
    case Boolean = 'boolean';
}
