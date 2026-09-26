<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Bridge\Cost\FinishedCard;
use App\Module\Bridge\View\CardCost;
use App\Module\Bridge\View\CostChart;
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

    public function test_the_time_axis_labels_at_most_six_days(): void
    {
        $chart = $this->chart([$this->cost('2026-09-10 09:00:00', ['' => 1_000_000])]);

        self::assertLessThanOrEqual(6, \count($chart->xTicks));
        self::assertEquals(new \DateTimeImmutable('2026-09-01 00:00:00'), $chart->xTicks[0]->day);
        self::assertFalse($chart->longSpan);
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
