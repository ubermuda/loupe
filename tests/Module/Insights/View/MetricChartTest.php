<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Insights\View\MetricChart;
use App\Module\Insights\View\MetricChartBar;
use App\Module\Insights\View\MetricChartBucket;
use App\Module\Insights\View\MetricChartSeries;
use App\Module\Insights\View\MetricChartTick;
use PHPUnit\Framework\TestCase;

final class MetricChartTest extends TestCase
{
    public function test_no_series_gives_no_chart(): void
    {
        self::assertNull(MetricChart::build(Metric::Cost, MetricBucket::Week, []));
    }

    public function test_one_series_draws_one_bar_per_bucket_on_a_nice_axis(): void
    {
        $chart = MetricChart::build(Metric::Cost, MetricBucket::Day, [self::series(null, [
            '2026-10-01' => 1.0,
            '2026-10-02' => 3.0,
            '2026-10-04' => 2.0,
            '2026-10-05' => 0.5,
        ])]);

        self::assertNotNull($chart);
        self::assertCount(4, $chart->buckets);
        // The plot is 872 wide, so each of the four buckets takes 218 and centres a bar of the widest 48.
        self::assertSame([149.0, 367.0, 585.0, 803.0], array_map(static fn (MetricChartBucket $bucket): float => $bucket->bars[0]->x, $chart->buckets));
        self::assertSame([48.0], array_unique(array_map(static fn (MetricChartBucket $bucket): float => $bucket->bars[0]->width, $chart->buckets)));
        self::assertSame([173.0, 391.0, 609.0, 827.0], array_map(static fn (MetricChartBucket $bucket): float => $bucket->centre, $chart->buckets));
        // The tallest value, 3, tops the axis and fills the plot from 248 up to 16.
        self::assertSame([170.67, 16.0, 93.33, 209.33], array_map(static fn (MetricChartBucket $bucket): float => $bucket->bars[0]->top, $chart->buckets));
        self::assertEquals([
            new MetricChartTick(248.0, 0.0),
            new MetricChartTick(170.67, 1.0),
            new MetricChartTick(93.33, 2.0),
            new MetricChartTick(16.0, 3.0),
        ], $chart->yTicks);
        self::assertSame(0, $chart->moneyDecimals);
        self::assertSame(['2026-10-01', '2026-10-02', '2026-10-04', '2026-10-05'], array_map(static fn (MetricChartTick $tick): string => $tick->start?->format('Y-m-d') ?? '', $chart->xTicks));
        self::assertFalse($chart->longSpan);
        self::assertCount(1, $chart->series);
        self::assertFalse($chart->hasLegend());
    }

    public function test_a_bar_keeps_its_point_and_its_series(): void
    {
        $chart = MetricChart::build(Metric::Cost, MetricBucket::Week, [self::series('implement', ['2026-09-28' => 2.0], rows: 3)]);

        self::assertNotNull($chart);
        $bar = $chart->buckets[0]->bars[0];
        self::assertSame(2.0, $bar->point->value);
        self::assertSame(3, $bar->point->rows);
        self::assertEquals(new MetricChartSeries('implement', 1, 0), $bar->series);
        // One bucket takes the whole plot and centres its bar. The top corners round, the baseline end stays square.
        self::assertSame('M476 248V20Q476 16 480 16H520Q524 16 524 20V248Z', $bar->path);
    }

    public function test_two_series_stand_side_by_side_in_one_bucket(): void
    {
        $chart = MetricChart::build(Metric::Duration, MetricBucket::Week, [
            self::series('plan', ['2026-09-28' => 1000]),
            self::series('review', ['2026-09-28' => 4000]),
        ]);

        self::assertNotNull($chart);
        self::assertCount(1, $chart->buckets);
        $bars = $chart->buckets[0]->bars;
        // One bucket takes the whole plot. The two bars of 48 and the gap of 2 between them centre on 500.
        self::assertSame([451.0, 501.0], array_map(static fn (MetricChartBar $bar): float => $bar->x, $bars));
        self::assertSame([1, 2], array_map(static fn (MetricChartBar $bar): int => $bar->series->slot, $bars));
        self::assertSame([190.0, 16.0], array_map(static fn (MetricChartBar $bar): float => $bar->top, $bars));
        self::assertEquals([new MetricChartTick(500.0, start: new \DateTimeImmutable('2026-09-28 00:00:00 UTC'))], $chart->xTicks);
        self::assertTrue($chart->hasLegend());
    }

    public function test_a_series_missing_from_a_bucket_keeps_its_place_in_the_group(): void
    {
        $chart = MetricChart::build(Metric::Cost, MetricBucket::Week, [
            self::series('plan', ['2026-09-21' => 1.0]),
            self::series('review', ['2026-09-21' => 1.0, '2026-09-28' => 1.0]),
        ]);

        self::assertNotNull($chart);
        self::assertSame('2026-09-28', $chart->buckets[1]->start->format('Y-m-d'));
        self::assertCount(1, $chart->buckets[1]->bars);
        self::assertSame($chart->buckets[0]->bars[1]->x + 436, $chart->buckets[1]->bars[0]->x);
    }

    public function test_a_point_with_no_known_value_draws_no_bar(): void
    {
        $chart = MetricChart::build(Metric::Cost, MetricBucket::Week, [self::series(null, ['2026-09-21' => null, '2026-09-28' => 0.02])]);

        self::assertNotNull($chart);
        self::assertCount(2, $chart->buckets);
        self::assertSame([], $chart->buckets[0]->bars);
        self::assertCount(1, $chart->buckets[1]->bars);
        // A chart of cents keeps an axis of four cents, so a tiny cost still reads.
        self::assertSame(0.04, $chart->yTicks[\count($chart->yTicks) - 1]->value);
        self::assertSame(2, $chart->moneyDecimals);
    }

    public function test_a_zero_value_draws_a_bar_with_no_height(): void
    {
        $chart = MetricChart::build(Metric::Runs, MetricBucket::Week, [self::series(null, ['2026-09-28' => 0])]);

        self::assertNotNull($chart);
        $bar = $chart->buckets[0]->bars[0];
        self::assertSame(248.0, $bar->top);
        self::assertSame('', $bar->path);
    }

    public function test_a_ratio_axis_tops_at_one_hundred_percent(): void
    {
        $chart = MetricChart::build(Metric::MergeRate, MetricBucket::Week, [self::series(null, ['2026-09-28' => 0.3])]);

        self::assertNotNull($chart);
        self::assertSame([0.0, 0.25, 0.5, 0.75, 1.0], array_map(static fn (MetricChartTick $tick): float => $tick->value, $chart->yTicks));
    }

    public function test_a_small_money_step_takes_more_decimals(): void
    {
        $chart = MetricChart::build(Metric::Cost, MetricBucket::Week, [self::series(null, ['2026-09-28' => 0.018])]);

        self::assertNotNull($chart);
        self::assertSame([0.0, 0.01, 0.02, 0.03, 0.04], array_map(static fn (MetricChartTick $tick): float => $tick->value, $chart->yTicks));
        self::assertSame(2, $chart->moneyDecimals);

        $tiny = MetricChart::build(Metric::Cost, MetricBucket::Week, [self::series(null, ['2026-09-28' => 0.00001])]);
        self::assertNotNull($tiny);
        self::assertSame(2, $tiny->moneyDecimals);
    }

    public function test_more_than_eight_series_share_the_grey_past_the_seventh(): void
    {
        $series = [];
        foreach (range(1, 9) as $index) {
            $series[] = self::series('group-'.$index, ['2026-09-28' => 1.0]);
        }

        $chart = MetricChart::build(Metric::Cost, MetricBucket::Week, $series);

        self::assertNotNull($chart);
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 0, 0], array_map(static fn (MetricChartSeries $item): int => $item->slot, $chart->series));
    }

    public function test_a_long_day_axis_labels_every_seventh_bucket(): void
    {
        $values = [];
        for ($day = 1; $day <= 30; ++$day) {
            $values[\sprintf('2026-09-%02d', $day)] = 1.0;
        }

        $chart = MetricChart::build(Metric::Cost, MetricBucket::Day, [self::series(null, $values)]);

        self::assertNotNull($chart);
        self::assertSame(['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29'], array_map(static fn (MetricChartTick $tick): string => $tick->start?->format('Y-m-d') ?? '', $chart->xTicks));
    }

    public function test_an_axis_over_more_than_a_year_needs_the_year(): void
    {
        $chart = MetricChart::build(Metric::Cost, MetricBucket::Month, [self::series(null, ['2025-01-01' => 1.0, '2026-09-01' => 1.0])]);

        self::assertNotNull($chart);
        self::assertTrue($chart->longSpan);
    }

    /** @param array<string, int|float|null> $values bucket start => value */
    private static function series(?string $group, array $values, int $rows = 1): MetricSeries
    {
        $points = [];
        foreach ($values as $start => $value) {
            $points[] = new MetricPoint(new \DateTimeImmutable($start.' 00:00:00 UTC'), $value, null === $value ? 0 : $rows);
        }

        return new MetricSeries($group, $points, new MetricPoint(null, null, 0), []);
    }
}
