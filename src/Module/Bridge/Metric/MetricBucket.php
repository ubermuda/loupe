<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

enum MetricBucket: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** UTC midnight of the day, of the ISO Monday, or of the first of the month. */
    public function startOf(\DateTimeImmutable $time): \DateTimeImmutable
    {
        $day = $time->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);

        return match ($this) {
            self::Day => $day,
            self::Week => $day->modify(\sprintf('-%d days', (int) $day->format('N') - 1)),
            self::Month => $day->setDate((int) $day->format('Y'), (int) $day->format('n'), 1),
        };
    }
}
