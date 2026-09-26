<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Bridge\Cost\FinishedCard;
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

    public function test_a_bar_stands_at_its_completion_day_with_its_height_in_dollars(): void
    {
        $chart = $this->chart([
            $this->cost('2026-09-01 09:00:00', ['' => 1_000_000]),
            $this->cost('2026-09-30 09:00:00', ['' => 2_000_000]),
        ]);

        [$first, $last] = $chart->bars;
        self::assertLessThan($last->x, $first->x);
        self::assertGreaterThanOrEqual(CostChart::PLOT_LEFT, $first->x);
        self::assertLessThanOrEqual(CostChart::PLOT_RIGHT, $last->x + $last->width);
        // The tallest bar reaches the top tick, and the other is half as tall.
        self::assertSame((float) CostChart::PLOT_TOP, $last->top);
        self::assertEqualsWithDelta((CostChart::BASELINE + CostChart::PLOT_TOP) / 2, $first->top, 0.01);
        self::assertLessThanOrEqual(24.0, $last->width);
    }

    public function test_cards_finished_on_one_day_stand_side_by_side(): void
    {
        $chart = $this->chart([
            $this->cost('2026-09-10 09:00:00', ['' => 1_000_000]),
            $this->cost('2026-09-10 17:00:00', ['' => 1_000_000]),
        ]);

        [$morning, $evening] = $chart->bars;
        self::assertGreaterThanOrEqual($morning->x + $morning->width + 2, $evening->x + 0.01);
    }

    /** Twenty bars of two pixels and their gaps are wider than one day, so the gaps and then the bars give way. */
    public function test_a_crowded_day_keeps_every_bar_inside_its_slot(): void
    {
        $cards = [];
        for ($hour = 0; $hour < 20; ++$hour) {
            $cards[] = $this->cost(\sprintf('2026-09-10 %02d:00:00', $hour), ['' => 1_000_000]);
        }
        $chart = $this->chart($cards);

        // The range spans 30 days, and the tenth day is the slot of the group.
        $dayWidth = (CostChart::PLOT_RIGHT - CostChart::PLOT_LEFT) / 30;
        $slotLeft = CostChart::PLOT_LEFT + 9 * $dayWidth;
        $previousRight = $slotLeft;
        // The view box coordinates are rounded to two decimals, so an edge can move by up to 0.015.
        foreach ($chart->bars as $bar) {
            self::assertGreaterThan(0.0, $bar->width);
            self::assertGreaterThanOrEqual($previousRight - 0.02, $bar->x);
            self::assertGreaterThanOrEqual($bar->x + $bar->width - 0.02, $bar->hitX + $bar->hitWidth);
            self::assertLessThanOrEqual($bar->x + 0.02, $bar->hitX);
            $previousRight = $bar->x + $bar->width;
        }
        self::assertLessThanOrEqual($slotLeft + $dayWidth + 0.02, $previousRight);
        self::assertGreaterThanOrEqual($slotLeft - 0.02, $chart->bars[0]->hitX);
        self::assertLessThanOrEqual($slotLeft + $dayWidth + 0.02, $chart->bars[19]->hitX + $chart->bars[19]->hitWidth);
    }

    /** A day of 600 is under six units wide, so the bars group by ISO week. */
    public function test_a_long_range_groups_by_week_and_keeps_bars_and_hit_areas_in_order(): void
    {
        $cards = [];
        for ($hour = 9; $hour < 13; ++$hour) {
            $cards[] = $this->cost(\sprintf('2025-06-08 %02d:00:00', $hour), ['' => 1_000_000]);
        }
        // A Monday, so this card opens the next week.
        $cards[] = $this->cost('2025-06-09 09:00:00', ['' => 1_000_000]);
        $chart = CostChart::build($cards, [''], new \DateTimeImmutable('2025-02-07 12:00:00'), new \DateTimeImmutable('2026-09-30 12:00:00'));

        self::assertSame('week', $chart->period);
        $this->assertInOrder($chart->bars);
        // The axis runs from the Monday of the first week to the Sunday of the last week.
        $mondayLeft = $this->axisX('2025-02-03', '2025-06-09', 609);
        self::assertLessThanOrEqual($mondayLeft + 0.02, $chart->bars[3]->hitX + $chart->bars[3]->hitWidth);
        self::assertGreaterThanOrEqual($mondayLeft - 0.02, $chart->bars[4]->hitX);
    }

    /** Over three years a week is under six units wide, so the bars group by month. */
    public function test_a_three_year_range_groups_by_month(): void
    {
        $chart = CostChart::build(
            [
                $this->cost('2024-03-02 09:00:00', ['' => 1_000_000]),
                $this->cost('2024-03-30 09:00:00', ['' => 1_000_000]),
                $this->cost('2024-04-01 09:00:00', ['' => 1_000_000]),
            ],
            [''],
            new \DateTimeImmutable('2023-09-30 12:00:00'),
            new \DateTimeImmutable('2026-09-30 12:00:00'),
        );

        self::assertSame('month', $chart->period);
        $this->assertInOrder($chart->bars);
        // The axis runs from the first day of the first month to the last day of the last month.
        $marchLeft = $this->axisX('2023-09-01', '2024-03-01', 1126);
        $aprilLeft = $this->axisX('2023-09-01', '2024-04-01', 1126);
        self::assertEqualsWithDelta($marchLeft, $chart->bars[0]->hitX, 0.02);
        self::assertEqualsWithDelta($aprilLeft, $chart->bars[1]->hitX + $chart->bars[1]->hitWidth, 0.02);
        self::assertEqualsWithDelta($aprilLeft, $chart->bars[2]->hitX, 0.02);
        self::assertGreaterThanOrEqual(2.0, $chart->bars[2]->width);
    }

    /** A range that ends on the first day of a month still draws that month whole. */
    public function test_the_last_month_of_a_range_keeps_its_full_width(): void
    {
        $chart = CostChart::build(
            [
                $this->cost('2025-09-15 09:00:00', ['' => 1_000_000]),
                $this->cost('2026-09-01 09:00:00', ['' => 1_000_000]),
            ],
            [''],
            new \DateTimeImmutable('2023-09-01 12:00:00'),
            new \DateTimeImmutable('2026-09-01 12:00:00'),
        );

        self::assertSame('month', $chart->period);
        // Both Septembers have 30 days.
        self::assertEqualsWithDelta($chart->bars[0]->hitWidth, $chart->bars[1]->hitWidth, 0.02);
    }

    public function test_the_parts_stack_bottom_up_with_a_gap_and_a_rounded_top(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['build' => 1_000_000, 'plan' => 3_000_000])], ['build', 'plan']);

        $bar = $chart->bars[0];
        self::assertSame([1, 2], array_map(static fn (CostChartSegment $segment): int => $segment->slot, $bar->segments));
        self::assertStringNotContainsString('Q', $bar->segments[0]->path);
        self::assertStringContainsString('Q', $bar->segments[1]->path);
        self::assertSame(['build', 'plan'], array_map(static fn (CostChartSeries $series): ?string => $series->key, $chart->series));
    }

    public function test_a_colour_follows_its_key_whatever_the_filter_shows(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['plan' => 1_000_000])], ['build', 'plan']);

        self::assertSame(2, $chart->bars[0]->segments[0]->slot);
    }

    public function test_keys_past_the_palette_share_the_grey_of_the_rest_at_the_end_of_the_legend(): void
    {
        $keys = array_map(static fn (int $index): string => 'rule-'.$index, range(1, 9));
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['rule-1' => 1_000_000, 'rule-8' => 1_000_000, 'rule-9' => 1_000_000])], $keys);

        self::assertSame([1, 0, 0], array_map(static fn (CostChartSegment $segment): int => $segment->slot, $chart->bars[0]->segments));
        self::assertSame([[1, 'rule-1'], [0, null]], array_map(static fn (CostChartSeries $series): array => [$series->slot, $series->key], $chart->series));
    }

    public function test_a_card_with_no_priced_usage_keeps_a_stub_for_its_marks(): void
    {
        $chart = $this->chart([
            $this->cost('2026-09-10 09:00:00', ['' => 0]),
            $this->cost('2026-09-11 09:00:00', ['' => 1_000_000]),
        ]);

        self::assertTrue($chart->bars[0]->isStub());
        self::assertSame(CostChart::BASELINE - 2.0, $chart->bars[0]->top);
        self::assertFalse($chart->bars[1]->isStub());
    }

    /** A priced part far shorter than the gap between parts is still drawn, above the part below it. */
    public function test_a_tiny_priced_part_keeps_a_segment_of_its_own(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['build' => 1_000_000, 'plan' => 1_000])], ['build', 'plan']);

        $this->assertStacked($chart->bars[0]);
        self::assertSame([false, false], array_map(static fn (CostChartSegment $segment): bool => $segment->estimated, $chart->bars[0]->segments));
    }

    /** The hatch of a tiny estimated part stays on that part and does not spread to the whole bar. */
    public function test_a_tiny_estimated_part_carries_the_estimate_alone(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['build' => 1_000_000, 'plan' => 1_000], ['plan'])], ['build', 'plan']);

        $this->assertStacked($chart->bars[0]);
        self::assertSame([false, true], array_map(static fn (CostChartSegment $segment): bool => $segment->estimated, $chart->bars[0]->segments));
        self::assertFalse($chart->bars[0]->hasHiddenEstimate());
    }

    public function test_only_the_estimated_part_of_a_bar_carries_the_estimate(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['build' => 1_000_000, 'plan' => 1_000_000], ['plan'])], ['build', 'plan']);

        self::assertSame([false, true], array_map(static fn (CostChartSegment $segment): bool => $segment->estimated, $chart->bars[0]->segments));
        self::assertFalse($chart->bars[0]->hasHiddenEstimate());
    }

    /** A part with no price paints nothing, so the mark must move to the whole bar. */
    public function test_an_unpriced_estimate_marks_the_whole_bar(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['build' => 1_000_000, 'plan' => 0], ['plan'])], ['build', 'plan']);

        self::assertCount(1, $chart->bars[0]->segments);
        self::assertTrue($chart->bars[0]->hasHiddenEstimate());
    }

    public function test_the_value_axis_has_round_dollar_ticks_from_zero(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['' => 3_300_000])]);

        self::assertSame([0.0, 1.0, 2.0, 3.0, 4.0], array_map(static fn (CostChartTick $tick): float => $tick->amount, $chart->yTicks));
        self::assertSame(0, $chart->yTickDecimals);
        self::assertSame((float) CostChart::BASELINE, $chart->yTicks[0]->position);
    }

    public function test_cents_get_two_decimals_on_the_value_axis(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['' => 180_000])]);

        self::assertSame([0.0, 0.05, 0.1, 0.15, 0.2], array_map(static fn (CostChartTick $tick): float => round($tick->amount, 2), $chart->yTicks));
        self::assertSame(2, $chart->yTickDecimals);
    }

    /** Unpriced usage alone sums to nothing, and the axis still reads in cents. */
    public function test_an_axis_with_no_priced_usage_counts_in_cents(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['' => 0], [''])]);

        self::assertSame([0.0, 0.01, 0.02, 0.03, 0.04], array_map(static fn (CostChartTick $tick): float => round($tick->amount, 2), $chart->yTicks));
        self::assertSame(2, $chart->yTickDecimals);
    }

    public function test_the_time_axis_labels_at_most_six_days(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['' => 1_000_000])]);

        self::assertLessThanOrEqual(6, \count($chart->xTicks));
        self::assertEquals(new \DateTimeImmutable('2026-09-01 00:00:00'), $chart->xTicks[0]->day);
        self::assertFalse($chart->longSpan);
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

    /** Two segments, the second at least one unit tall and above the first, with the bar top on the second. */
    private function assertStacked(CostChartBar $bar): void
    {
        self::assertCount(2, $bar->segments);
        [$large, $tiny] = $bar->segments;
        self::assertSame([1, 2], [$large->slot, $tiny->slot]);
        self::assertGreaterThanOrEqual(0.99, $tiny->bottom - $tiny->top);
        self::assertLessThanOrEqual($large->top + 0.01, $tiny->bottom);
        self::assertEqualsWithDelta($tiny->top, $bar->top, 0.01);
    }

    /** The left edge of a day on an axis that starts on the given day and holds the given number of days. */
    private function axisX(string $firstDay, string $day, int $dayCount): float
    {
        $index = (int) new \DateTimeImmutable($firstDay)->diff(new \DateTimeImmutable($day))->format('%a');

        return CostChart::PLOT_LEFT + $index * (CostChart::PLOT_RIGHT - CostChart::PLOT_LEFT) / $dayCount;
    }

    /**
     * @param non-empty-list<CardCost> $cards
     * @param list<string>             $keys
     */
    private function chart(array $cards, array $keys = ['']): CostChart
    {
        return CostChart::build($cards, $keys, new \DateTimeImmutable(self::FROM), new \DateTimeImmutable(self::TO));
    }

    /**
     * @param non-empty-array<string, int> $parts
     * @param list<string>                 $estimated the keys of the estimated parts
     */
    private function cost(string $completedAt, array $parts, array $estimated = []): CardCost
    {
        $costParts = [];
        foreach ($parts as $key => $micros) {
            $costParts[] = new CostPart((string) $key, $micros, 1, 1, 1, 1, \in_array((string) $key, $estimated, true));
        }

        return new CardCost(new FinishedCard(Uuid::v7(), 1, 'Card', new \DateTimeImmutable($completedAt)), $costParts, 0);
    }
}
