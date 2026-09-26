<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** The stretch of time that one bar of the cost chart stands for. A week starts on Monday, as in ISO 8601. */
enum CostGroup: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public static function defaultFor(CostRange $range): self
    {
        return match ($range) {
            CostRange::ThirtyDays => self::Day,
            CostRange::NinetyDays => self::Week,
            CostRange::AllTime => self::Month,
        };
    }

    /** The first day of the period that holds a day. */
    public function start(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return match ($this) {
            self::Day => $day,
            self::Week => $day->modify(\sprintf('-%d days', (int) $day->format('N') - 1)),
            self::Month => $day->modify('first day of this month'),
        };
    }

    /** The day after the last day of the period that starts on a day. */
    public function end(\DateTimeImmutable $start): \DateTimeImmutable
    {
        return match ($this) {
            self::Day => $start->modify('+1 day'),
            self::Week => $start->modify('+7 days'),
            self::Month => $start->modify('first day of next month'),
        };
    }

    public function translationKey(): string
    {
        return 'bridge.worker_run_cost.group.'.$this->value;
    }
}
