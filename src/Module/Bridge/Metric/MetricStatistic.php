<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

enum MetricStatistic: string
{
    case Median = 'median';
    case Mean = 'mean';
    case Sum = 'sum';
    case P90 = 'p90';
    case Count = 'count';

    /**
     * Null for no value. A percentile interpolates between ranks, as numpy's default does.
     *
     * @param list<int|float> $values
     */
    public function of(array $values): int|float|null
    {
        if ([] === $values) {
            return null;
        }

        return match ($this) {
            self::Count => \count($values),
            self::Sum => array_sum($values),
            self::Mean => array_sum($values) / \count($values),
            self::Median => self::percentile($values, 0.5),
            self::P90 => self::percentile($values, 0.9),
        };
    }

    /** @param non-empty-list<int|float> $values */
    private static function percentile(array $values, float $fraction): float
    {
        sort($values);
        $rank = (\count($values) - 1) * $fraction;
        $low = (int) floor($rank);
        $high = min($low + 1, \count($values) - 1);

        return $values[$low] + ($rank - $low) * ($values[$high] - $values[$low]);
    }
}
