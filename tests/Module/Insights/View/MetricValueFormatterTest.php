<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Insights\View\MetricValueFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricValueFormatterTest extends TestCase
{
    /** @return iterable<string, array{Metric, int|float, string}> */
    public static function values(): iterable
    {
        yield 'money' => [Metric::Cost, 1.5, '$1.50'];
        yield 'money rounds to cents' => [Metric::Cost, 0.123456, '$0.12'];
        yield 'tokens' => [Metric::InputTokens, 1234567, '1,234,567'];
        yield 'a mean of tokens keeps one decimal' => [Metric::OutputTokens, 1234.56, '1,234.6'];
        yield 'count' => [Metric::Runs, 3, '3'];
        yield 'milliseconds' => [Metric::Duration, 450, '450 ms'];
        yield 'seconds' => [Metric::Duration, 12_400, '12 s'];
        yield 'minutes and seconds' => [Metric::Duration, 192_000, '3 min 12 s'];
        yield 'hours and minutes' => [Metric::Duration, 7_500_000, '2 h 5 min'];
        yield 'a fraction of a millisecond rounds' => [Metric::Duration, 1.6, '2 ms'];
        yield 'hours to merge are hours already' => [Metric::HoursToMerge, 5.24, '5.2 h'];
        yield 'ratio' => [Metric::MergeRate, 0.125, '12.5%'];
        yield 'a whole ratio keeps its decimal' => [Metric::StopRate, 1, '100.0%'];
        yield 'zero ratio' => [Metric::StopRate, 0, '0.0%'];
    }

    #[DataProvider('values')]
    public function test_it_formats_a_value_by_the_type_of_its_metric(Metric $metric, int|float $value, string $expected): void
    {
        self::assertSame($expected, new MetricValueFormatter('en')->format($value, $metric));
    }

    /** @return iterable<string, array{Metric}> */
    public static function metricsOfOtherTypes(): iterable
    {
        yield 'money' => [Metric::Cost];
        yield 'duration' => [Metric::Duration];
        yield 'hours to merge' => [Metric::HoursToMerge];
        yield 'stop rate' => [Metric::StopRate];
        yield 'merge rate' => [Metric::MergeRate];
    }

    #[DataProvider('metricsOfOtherTypes')]
    public function test_a_count_reads_as_a_count_whatever_the_metric(Metric $metric): void
    {
        self::assertSame('2', new MetricValueFormatter('en')->format(2, $metric, MetricStatistic::Count));
        self::assertSame('1,234', new MetricValueFormatter('en')->format(1234, $metric, MetricStatistic::Count));
    }

    public function test_an_unknown_value_has_no_text(): void
    {
        self::assertNull(new MetricValueFormatter('en')->format(null, Metric::Cost));
    }

    public function test_money_takes_more_decimals_for_a_small_axis_step(): void
    {
        self::assertSame('$0.005', new MetricValueFormatter('en')->format(0.005, Metric::Cost, moneyDecimals: 3));
        self::assertSame('$5', new MetricValueFormatter('en')->format(5, Metric::Cost, moneyDecimals: 0));
    }
}
