<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric;

use App\Module\Bridge\Metric\MetricStatistic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricStatisticTest extends TestCase
{
    /** @return iterable<string, array{MetricStatistic, list<int|float>, int|float}> */
    public static function samples(): iterable
    {
        yield 'count' => [MetricStatistic::Count, [3, 1, 2], 3];
        yield 'sum of integers' => [MetricStatistic::Sum, [3, 1, 2], 6];
        yield 'sum of floats' => [MetricStatistic::Sum, [0.5, 0.25], 0.75];
        yield 'mean' => [MetricStatistic::Mean, [1, 2, 4], 7 / 3];
        yield 'median of an odd count' => [MetricStatistic::Median, [5, 1, 3], 3];
        yield 'median of an even count' => [MetricStatistic::Median, [4, 1, 3, 2], 2.5];
        yield 'median of one value' => [MetricStatistic::Median, [7], 7];
        yield 'p90 interpolates between ranks' => [MetricStatistic::P90, [10, 9, 8, 7, 6, 5, 4, 3, 2, 1], 9.1];
        yield 'p90 of two values' => [MetricStatistic::P90, [0, 10], 9.0];
        yield 'p90 of one value' => [MetricStatistic::P90, [4.5], 4.5];
    }

    /** @param list<int|float> $values */
    #[DataProvider('samples')]
    public function test_it_computes_the_statistic(MetricStatistic $statistic, array $values, int|float $expected): void
    {
        self::assertEqualsWithDelta($expected, $statistic->of($values), 1e-9);
    }

    public function test_count_and_sum_of_integers_stay_integers(): void
    {
        self::assertSame(3, MetricStatistic::Count->of([1.5, 2.5, 3.5]));
        self::assertSame(6, MetricStatistic::Sum->of([1, 2, 3]));
    }

    public function test_every_statistic_of_no_value_is_null(): void
    {
        foreach (MetricStatistic::cases() as $statistic) {
            self::assertNull($statistic->of([]), $statistic->value);
        }
    }
}
