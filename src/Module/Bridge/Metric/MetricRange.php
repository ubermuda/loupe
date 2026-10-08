<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

enum MetricRange: string
{
    case ThirtyDays = 'thirty-days';
    case NinetyDays = 'ninety-days';
    case All = 'all';

    /** Null for all time. */
    public function startFrom(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return match ($this) {
            self::ThirtyDays => $now->modify('-30 days'),
            self::NinetyDays => $now->modify('-90 days'),
            self::All => null,
        };
    }
}
