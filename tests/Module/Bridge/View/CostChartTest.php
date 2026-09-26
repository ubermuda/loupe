<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Bridge\Cost\FinishedCard;
use App\Module\Bridge\ValueObject\CostGroup;
use App\Module\Bridge\View\CardCost;
use App\Module\Bridge\View\CostChart;
use App\Module\Bridge\View\CostChartBar;
use App\Module\Bridge\View\CostChartSegment;
use App\Module\Bridge\View\CostChartSeries;
use App\Module\Bridge\View\CostChartTick;
use App\Module\Bridge\View\CostPart;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CostChartTest extends TestCase
{
    private const string FROM = '2026-09-01 12:00:00';
    private const string TO = '2026-09-30 12:00:00';

    public function test_a_bar_stands_at_its_day_with_its_height_in_dollars(): void
    {
        $chart = $this->chart([
            $this->cost('2026-09-01 09:00:00', ['' => 1_000_000]),
            $this->cost('2026-09-30 09:00:00', ['' => 2_000_000]),
        ]);

        [$first, $last] = $chart->bars;
        self::assertEquals(new \DateTimeImmutable('2026-09-01'), $first->periodStart);
        self::assertLessThan($last->x, $first->x);
        self::assertGreaterThanOrEqual(CostChart::PLOT_LEFT, $first->x);
        self::assertLessThanOrEqual(CostChart::PLOT_RIGHT, $last->x + $last->width);
        // The tallest bar reaches the top tick, and the other is half as tall.
        self::assertSame((float) CostChart::PLOT_TOP, $last->top);
        self::assertEqualsWithDelta((CostChart::BASELINE + CostChart::PLOT_TOP) / 2, $first->top, 0.01);
        self::assertLessThanOrEqual(24.0, $last->width);
    }

    public function test_the_cards_of_one_period_share_one_bar_as_tall_as_their_average(): void
    {
        $chart = $this->chart([
            $this->cost('2026-09-10 09:00:00', ['' => 1_000_000]),
            $this->cost('2026-09-10 17:00:00', ['' => 3_000_000]),
            $this->cost('2026-09-20 09:00:00', ['' => 4_000_000]),
        ]);

        self::assertCount(2, $chart->bars);
        [$pair, $single] = $chart->bars;
        self::assertSame(2, $pair->cardCount());
        self::assertSame(2_000_000, $pair->averageMicros);
        self::assertSame(4_000_000, $single->averageMicros);
        self::assertEqualsWithDelta((CostChart::BASELINE + CostChart::PLOT_TOP) / 2, $pair->top, 0.01);
    }

    public function test_each_part_is_the_average_of_its_key_and_the_parts_add_up_to_the_bar(): void
    {
        $chart = $this->chart([
            $this->cost('2026-09-10 09:00:00', ['build' => 1_000_000, 'plan' => 3_000_000]),
            $this->cost('2026-09-10 17:00:00', ['build' => 1_000_000]),
        ], ['build', 'plan']);

        $bar = $chart->bars[0];
        self::assertSame(['build' => 1_000_000, 'plan' => 1_500_000], $bar->partAverages);
        self::assertSame(2_500_000, $bar->averageMicros);
        self::assertSame([1, 2], array_map(static fn (CostChartSegment $segment): int => $segment->slot, $bar->segments));
        self::assertEqualsWithDelta($bar->top, $bar->segments[1]->top, 0.01);
        // The parts stack from the baseline with one gap between them.
        $painted = array_sum(array_map(static fn (CostChartSegment $segment): float => $segment->bottom - $segment->top, $bar->segments));
        self::assertEqualsWithDelta(CostChart::BASELINE - $bar->top, $painted + 2.0, 0.02);
        $plotHeight = CostChart::BASELINE - CostChart::PLOT_TOP;
        $topMicros = (int) round($chart->yTicks[\count($chart->yTicks) - 1]->amount * 1_000_000);
        self::assertEqualsWithDelta(2_500_000 / $topMicros * $plotHeight, CostChart::BASELINE - $bar->top, 0.02);
    }

    public function test_a_week_starts_on_monday(): void
    {
        $chart = $this->chart([
            $this->cost('2026-09-13 22:00:00', ['' => 1_000_000]),
            $this->cost('2026-09-14 08:00:00', ['' => 1_000_000]),
        ], group: CostGroup::Week);

        self::assertSame(['2026-09-07', '2026-09-14'], $this->periods($chart));
        $this->assertInOrder($chart->bars);
        // The axis opens on the Monday before the range starts, a Tuesday.
        $dayWidth = (CostChart::PLOT_RIGHT - CostChart::PLOT_LEFT) / 35;
        self::assertEqualsWithDelta(CostChart::PLOT_LEFT + 7 * $dayWidth, $chart->bars[0]->hitX, 0.02);
        self::assertEqualsWithDelta(7 * $dayWidth, $chart->bars[0]->hitWidth, 0.02);
    }

    /** A period start keeps the timezone of the dates, whatever the default of PHP. */
    public function test_a_bar_keeps_its_day_in_the_timezone_of_the_dates(): void
    {
        $utc = new \DateTimeZone('UTC');
        $default = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try {
            $chart = CostChart::build(
                [$this->cost('2026-09-02 09:00:00', ['' => 1_000_000], timezone: $utc)],
                [''],
                new \DateTimeImmutable(self::FROM, $utc),
                new \DateTimeImmutable(self::TO, $utc),
                CostGroup::Day,
                1_000_000,
            );
        } finally {
            date_default_timezone_set($default);
        }

        $dayWidth = (CostChart::PLOT_RIGHT - CostChart::PLOT_LEFT) / 30;
        self::assertSame('2026-09-02', $chart->bars[0]->periodStart->format('Y-m-d'));
        self::assertEqualsWithDelta(CostChart::PLOT_LEFT + $dayWidth, $chart->bars[0]->hitX, 0.02);
    }

    public function test_a_month_ends_on_its_last_day(): void
    {
        $chart = CostChart::build(
            [
                $this->cost('2026-03-31 22:00:00', ['' => 1_000_000]),
                $this->cost('2026-04-01 08:00:00', ['' => 1_000_000]),
            ],
            [''],
            new \DateTimeImmutable('2026-03-15 12:00:00'),
            new \DateTimeImmutable('2026-04-20 12:00:00'),
            CostGroup::Month,
            1_000_000,
        );

        self::assertSame(['2026-03-01', '2026-04-01'], $this->periods($chart));
        // March has 31 days and April 30, and the axis holds both whole.
        $dayWidth = (CostChart::PLOT_RIGHT - CostChart::PLOT_LEFT) / 61;
        self::assertEqualsWithDelta((float) CostChart::PLOT_LEFT, $chart->bars[0]->hitX, 0.02);
        self::assertEqualsWithDelta(31 * $dayWidth, $chart->bars[0]->hitWidth, 0.02);
        self::assertEqualsWithDelta(30 * $dayWidth, $chart->bars[1]->hitWidth, 0.02);
        self::assertSame(['Mar', 'Apr'], array_map(static fn (CostChartTick $tick): string => $tick->day?->format('M') ?? '', $chart->xTicks));
    }

    /** The default ninety-day view groups by week, and its bars have room for their labels. */
    public function test_ninety_days_by_week_gives_wide_bars(): void
    {
        $chart = CostChart::build(
            [$this->cost('2026-09-10 09:00:00', ['' => 1_000_000]), $this->cost('2026-09-11 09:00:00', ['' => 1_000_000])],
            [''],
            new \DateTimeImmutable('2026-06-28 12:00:00'),
            new \DateTimeImmutable('2026-09-26 12:00:00'),
            CostGroup::Week,
            1_000_000,
        );

        self::assertCount(1, $chart->bars);
        self::assertTrue($chart->bars[0]->isWide());
        self::assertFalse($chart->hasCountMark());
    }

    public function test_a_narrow_bar_of_several_cards_carries_a_count_mark(): void
    {
        $chart = $this->chart([
            $this->cost('2026-09-10 09:00:00', ['' => 1_000_000]),
            $this->cost('2026-09-10 17:00:00', ['' => 1_000_000]),
        ]);

        self::assertFalse($chart->bars[0]->isWide());
        self::assertTrue($chart->hasCountMark());
    }

    public function test_a_lone_narrow_card_needs_no_count_mark(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['' => 1_000_000])]);

        self::assertFalse($chart->hasCountMark());
    }

    public function test_the_median_line_sits_at_its_amount_on_the_value_axis(): void
    {
        $chart = $this->chart([
            $this->cost('2026-09-10 09:00:00', ['' => 1_000_000]),
            $this->cost('2026-09-11 09:00:00', ['' => 3_000_000]),
        ], medianMicros: 2_000_000);

        self::assertSame([0.0, 1.0, 2.0, 3.0], array_map(static fn (CostChartTick $tick): float => $tick->amount, $chart->yTicks));
        self::assertSame($chart->yTicks[2]->position, $chart->medianY);
        self::assertSame(2.0, $chart->median());
    }

    /** One costly card in a period of cheap ones can put the median above every average. */
    public function test_the_value_axis_reaches_the_median(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['' => 1_000_000])], medianMicros: 5_000_000);

        self::assertGreaterThanOrEqual(5.0, $chart->yTicks[\count($chart->yTicks) - 1]->amount);
        self::assertGreaterThanOrEqual((float) CostChart::PLOT_TOP, $chart->medianY);
    }

    public function test_one_estimated_card_hatches_its_whole_bar_and_one_partial_card_marks_it(): void
    {
        $plain = $this->cost('2026-09-10 09:00:00', ['' => 1_000_000]);
        $estimated = $this->cost('2026-09-10 17:00:00', ['' => 1_000_000], ['']);
        $partial = $this->cost('2026-09-20 09:00:00', ['' => 1_000_000], partialRuns: 2);
        $chart = $this->chart([$plain, $estimated, $partial]);

        self::assertSame([true, false], array_map(static fn (CostChartBar $bar): bool => $bar->estimated, $chart->bars));
        self::assertSame([false, true], array_map(static fn (CostChartBar $bar): bool => $bar->partial, $chart->bars));
        self::assertTrue($chart->hasEstimate());
        self::assertTrue($chart->hasPartial());
    }

    public function test_a_colour_follows_its_key_whatever_the_filter_shows(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['plan' => 1_000_000])], ['build', 'plan']);

        self::assertSame(2, $chart->bars[0]->segments[0]->slot);
        self::assertSame(['plan'], array_map(static fn (CostChartSeries $series): ?string => $series->key, $chart->series));
    }

    public function test_the_parts_stack_in_the_order_of_the_project_keys(): void
    {
        $chart = $this->chart([
            $this->cost('2026-09-10 09:00:00', ['plan' => 1_000_000]),
            $this->cost('2026-09-10 17:00:00', ['build' => 1_000_000]),
        ], ['build', 'plan']);

        self::assertSame(['build', 'plan'], array_keys($chart->bars[0]->partAverages));
        self::assertStringNotContainsString('Q', $chart->bars[0]->segments[0]->path);
        self::assertStringContainsString('Q', $chart->bars[0]->segments[1]->path);
    }

    public function test_keys_past_the_palette_share_the_grey_of_the_rest_at_the_end_of_the_legend(): void
    {
        $keys = array_map(static fn (int $index): string => 'rule-'.$index, range(1, 9));
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['rule-1' => 1_000_000, 'rule-8' => 1_000_000, 'rule-9' => 1_000_000])], $keys);

        self::assertSame([1, 0, 0], array_map(static fn (CostChartSegment $segment): int => $segment->slot, $chart->bars[0]->segments));
        self::assertSame([[1, 'rule-1'], [0, null]], array_map(static fn (CostChartSeries $series): array => [$series->slot, $series->key], $chart->series));
    }

    public function test_a_period_with_no_priced_usage_keeps_a_stub_for_its_marks(): void
    {
        $chart = $this->chart([
            $this->cost('2026-09-10 09:00:00', ['' => 0]),
            $this->cost('2026-09-11 09:00:00', ['' => 1_000_000]),
        ]);

        self::assertTrue($chart->bars[0]->isStub());
        self::assertSame(CostChart::BASELINE - 2.0, $chart->bars[0]->top);
        self::assertFalse($chart->bars[1]->isStub());
    }

    /** Twenty one-unit parts on a bar at the top tick would push it above the plot, so the tallest part gives the room back. */
    public function test_tiny_parts_never_push_a_bar_above_the_plot(): void
    {
        $parts = ['big' => 999_980];
        for ($index = 1; $index <= 20; ++$index) {
            $parts[\sprintf('tiny-%02d', $index)] = 1;
        }
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', $parts)], array_keys($parts));

        $bar = $chart->bars[0];
        self::assertCount(21, $bar->segments);
        self::assertEqualsWithDelta((float) CostChart::PLOT_TOP, $bar->top, 0.01);
        foreach ($bar->segments as $segment) {
            self::assertGreaterThanOrEqual(0.99, $segment->bottom - $segment->top);
            self::assertGreaterThanOrEqual(CostChart::PLOT_TOP - 0.01, $segment->top);
            self::assertLessThanOrEqual((float) CostChart::BASELINE, $segment->bottom);
        }
    }

    public function test_the_value_axis_has_round_dollar_ticks_from_zero(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['' => 3_300_000])], medianMicros: 3_300_000);

        self::assertSame([0.0, 1.0, 2.0, 3.0, 4.0], array_map(static fn (CostChartTick $tick): float => $tick->amount, $chart->yTicks));
        self::assertSame(0, $chart->yTickDecimals);
        self::assertSame((float) CostChart::BASELINE, $chart->yTicks[0]->position);
    }

    public function test_cents_get_two_decimals_on_the_value_axis(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['' => 180_000])], medianMicros: 180_000);

        self::assertSame([0.0, 0.05, 0.1, 0.15, 0.2], array_map(static fn (CostChartTick $tick): float => round($tick->amount, 2), $chart->yTicks));
        self::assertSame(2, $chart->yTickDecimals);
    }

    /** Unpriced usage alone sums to nothing, and the axis still reads in cents. */
    public function test_an_axis_with_no_priced_usage_counts_in_cents(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['' => 0], [''])], medianMicros: 0);

        self::assertSame([0.0, 0.01, 0.02, 0.03, 0.04], array_map(static fn (CostChartTick $tick): float => round($tick->amount, 2), $chart->yTicks));
        self::assertSame(2, $chart->yTickDecimals);
    }

    public function test_the_time_axis_labels_at_most_eight_periods(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['' => 1_000_000])]);

        self::assertLessThanOrEqual(8, \count($chart->xTicks));
        self::assertEquals(new \DateTimeImmutable('2026-09-01 00:00:00'), $chart->xTicks[0]->day);
        self::assertFalse($chart->longSpan);
    }

    /** Days over three years are thinner than a unit, and every bar still keeps its place. */
    public function test_a_long_range_by_day_keeps_its_bars_in_order(): void
    {
        $chart = CostChart::build(
            [$this->cost('2024-03-02 09:00:00', ['' => 1_000_000]), $this->cost('2024-03-03 09:00:00', ['' => 1_000_000])],
            [''],
            new \DateTimeImmutable('2023-09-30 12:00:00'),
            new \DateTimeImmutable('2026-09-30 12:00:00'),
            CostGroup::Day,
            1_000_000,
        );

        $this->assertInOrder($chart->bars);
        self::assertTrue($chart->longSpan);
        self::assertLessThanOrEqual(8, \count($chart->xTicks));
    }

    /**
     * Every bar and every hit area has a width, holds its place in x order, and
     * each hit area holds its bar. Coordinates are rounded to two decimals.
     *
     * @param list<CostChartBar> $bars
     */
    private function assertInOrder(array $bars): void
    {
        foreach ($bars as $index => $bar) {
            self::assertGreaterThan(0.0, $bar->width);
            self::assertGreaterThan(0.0, $bar->hitWidth);
            self::assertLessThanOrEqual($bar->x + 0.02, $bar->hitX);
            self::assertGreaterThanOrEqual($bar->x + $bar->width - 0.02, $bar->hitX + $bar->hitWidth);
            $next = $bars[$index + 1] ?? null;
            if (null !== $next) {
                self::assertGreaterThanOrEqual($bar->x + $bar->width - 0.02, $next->x);
                self::assertGreaterThanOrEqual($bar->hitX + $bar->hitWidth - 0.001, $next->hitX);
            }
        }
    }

    /** @return list<string> */
    private function periods(CostChart $chart): array
    {
        return array_map(static fn (CostChartBar $bar): string => $bar->periodStart->format('Y-m-d'), $chart->bars);
    }

    /**
     * @param non-empty-list<CardCost> $cards
     * @param list<string>             $keys
     */
    private function chart(array $cards, array $keys = [''], CostGroup $group = CostGroup::Day, int $medianMicros = 1_000_000): CostChart
    {
        return CostChart::build($cards, $keys, new \DateTimeImmutable(self::FROM), new \DateTimeImmutable(self::TO), $group, $medianMicros);
    }

    /**
     * @param non-empty-array<string, int> $parts
     * @param list<string>                 $estimated the keys of the estimated parts
     */
    private function cost(string $completedAt, array $parts, array $estimated = [], int $partialRuns = 0, ?\DateTimeZone $timezone = null): CardCost
    {
        $costParts = [];
        foreach ($parts as $key => $micros) {
            $costParts[] = new CostPart((string) $key, $micros, 1, 1, 1, 1, \in_array((string) $key, $estimated, true));
        }

        return new CardCost(new FinishedCard(Uuid::v7(), 1, 'Card', new \DateTimeImmutable($completedAt, $timezone)), $costParts, $partialRuns);
    }
}
