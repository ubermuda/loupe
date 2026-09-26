<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\ValueObject\CostGroup;

/**
 * The average cost per card of each period with finished cards, as a bar on
 * a time axis, laid out in the units of an SVG view box. Twig only prints
 * these numbers.
 */
final readonly class CostChart
{
    public const int WIDTH = 960;
    public const int HEIGHT = 280;
    public const int PLOT_LEFT = 64;
    /** The right margin holds the label of the median line. */
    public const int PLOT_RIGHT = 888;
    /** The top margin holds the two labels of the tallest bar. */
    public const int PLOT_TOP = 36;
    public const int BASELINE = 248;
    /** The palette holds eight colours. With more than eight keys, the first seven keep theirs and the rest share a grey. */
    public const int PALETTE_SIZE = 8;
    public const float WIDE_BAR_WIDTH = 40.0;

    private const float MAX_DAY_BAR_WIDTH = 24.0;
    private const float MAX_BAR_WIDTH = 96.0;
    /** The part of a week or a month that its bar fills. */
    private const float BAR_SHARE = 0.7;
    private const float MIN_BAR_WIDTH = 2.0;
    private const float GAP = 2.0;
    private const float MIN_PART_HEIGHT = 1.0;
    private const float RADIUS = 4.0;
    private const int TARGET_TICKS = 4;
    private const int MAX_TIME_TICKS = 8;
    /** Four cents, so a chart of unpriced usage still reads in cents. */
    private const int MIN_TOP_MICROS = 40_000;

    /**
     * @param list<CostChartBar>    $bars
     * @param list<CostChartSeries> $series the series the bars use, in palette order
     * @param list<CostChartTick>   $yTicks
     * @param list<CostChartTick>   $xTicks
     * @param array<string, int>    $slots  the palette slot of each key that has a colour of its own
     */
    public function __construct(
        public array $bars,
        public array $slots,
        public array $series,
        public array $yTicks,
        public int $yTickDecimals,
        public array $xTicks,
        /** True when the time axis spans more than a year, so a label needs its year. */
        public bool $longSpan,
        public CostGroup $group,
        /** Millionths of a dollar. */
        public int $medianMicros,
        /** The y of the median line. */
        public float $medianY,
    ) {
    }

    /**
     * @param non-empty-list<CardCost> $cards        oldest completion first
     * @param list<string>             $keys         every rule or model of the project, sorted,
     *                                               so a filter never gives a survivor a new colour
     * @param int                      $medianMicros the median cost per card of the cards
     */
    public static function build(array $cards, array $keys, \DateTimeImmutable $from, \DateTimeImmutable $to, CostGroup $group, int $medianMicros): self
    {
        $slots = self::slots($keys);
        $order = array_flip($keys);
        // The axis spans whole periods, so the first and last ones are never cut short.
        $firstDay = $group->start(self::day($from));
        $endDay = $group->end($group->start(self::day($to)));
        $dayCount = max(1, self::daysBetween($firstDay, $endDay));
        $dayWidth = (self::PLOT_RIGHT - self::PLOT_LEFT) / $dayCount;
        $plotHeight = self::BASELINE - self::PLOT_TOP;

        $periods = [];
        foreach ($cards as $cost) {
            $periods[$group->start(self::day($cost->card->completedAt))->format('Y-m-d')][] = $cost;
        }

        $averages = [];
        foreach ($periods as $start => $periodCards) {
            $count = \count($periodCards);
            $sums = [];
            foreach ($periodCards as $cost) {
                foreach ($cost->parts as $part) {
                    $sums[$part->key] = ($sums[$part->key] ?? 0) + $part->costMicros;
                }
            }
            uksort($sums, static fn (int|string $left, int|string $right): int => [$order[$left] ?? \PHP_INT_MAX, (string) $left] <=> [$order[$right] ?? \PHP_INT_MAX, (string) $right]);
            $averages[$start] = [
                'total' => array_sum(array_map(static fn (CardCost $cost): int => $cost->costMicros, $periodCards)) / $count,
                'parts' => array_map(static fn (int $sum): float => $sum / $count, $sums),
            ];
        }

        $maxMicros = max(self::MIN_TOP_MICROS, $medianMicros, ...array_map(static fn (array $average): float => $average['total'], array_values($averages)));
        $step = self::niceStep($maxMicros / self::TARGET_TICKS);
        $topMicros = (int) (ceil($maxMicros / $step) * $step);

        $bars = [];
        $usedSlots = [];
        foreach ($periods as $start => $periodCards) {
            $periodStart = new \DateTimeImmutable((string) $start);
            $startIndex = self::daysBetween($firstDay, $periodStart);
            $slotWidth = self::daysBetween($periodStart, $group->end($periodStart)) * $dayWidth;
            $slotLeft = self::PLOT_LEFT + $startIndex * $dayWidth;
            $width = self::barWidth($slotWidth, $group);
            $x = $slotLeft + ($slotWidth - $width) / 2;

            $painted = array_filter($averages[$start]['parts'], static fn (float $micros): bool => $micros > 0);
            $heights = array_values(array_map(static fn (float $micros): float => max(self::MIN_PART_HEIGHT, $micros / $topMicros * $plotHeight), $painted));
            // The minimum of the tiny parts must not lift the bar above the plot, so the tallest part gives the room back.
            $excess = array_sum($heights) - $plotHeight;
            if ([] !== $heights && $excess > 0) {
                $tallest = (int) array_search(max($heights), $heights, true);
                $heights[$tallest] = max(self::MIN_PART_HEIGHT, $heights[$tallest] - $excess);
            }
            $segments = [];
            $cursor = (float) self::BASELINE;
            foreach (array_keys($painted) as $index => $key) {
                $slot = $slots[$key] ?? 0;
                $usedSlots[$slot] = (string) $key;
                // Every priced part is drawn. Its gap comes out of its own height, and a part too
                // short to spare it gives up the gap rather than vanish.
                $partHeight = $heights[$index];
                $gap = 0 === $index ? 0.0 : min(self::GAP, $partHeight - self::MIN_PART_HEIGHT);
                $bottom = $cursor - $gap;
                $top = $cursor - $partHeight;
                $cursor = $top;
                $segments[] = new CostChartSegment($slot, self::path($x, $top, $width, $bottom - $top, $index === \count($painted) - 1), round($top, 2), round($bottom, 2));
            }
            $height = [] === $segments ? self::GAP : self::BASELINE - $cursor;
            $top = self::BASELINE - $height;
            $hitX = round($slotLeft, 2);

            $bars[] = new CostChartBar(
                periodStart: $periodStart,
                cards: $periodCards,
                averageMicros: (int) round($averages[$start]['total']),
                partAverages: array_map(static fn (float $micros): int => (int) round($micros), $averages[$start]['parts']),
                x: round($x, 2),
                width: round($width, 2),
                top: round($top, 2),
                segments: $segments,
                outline: self::path($x, $top, $width, $height, true),
                hitX: $hitX,
                hitWidth: round(round($slotLeft + $slotWidth, 2) - $hitX, 2),
                estimated: array_any($periodCards, static fn (CardCost $cost): bool => $cost->estimated),
                partial: array_any($periodCards, static fn (CardCost $cost): bool => $cost->partialRuns > 0),
            );
        }

        ksort($usedSlots);
        if (isset($usedSlots[0])) {
            // The grey of the other keys closes the legend.
            unset($usedSlots[0]);
            $usedSlots[0] = null;
        }

        $yTicks = [];
        for ($micros = 0; $micros <= $topMicros; $micros += $step) {
            $yTicks[] = new CostChartTick(round(self::BASELINE - $micros / $topMicros * $plotHeight, 2), $micros / 1_000_000);
        }

        $periodStarts = [];
        for ($periodStart = $firstDay; $periodStart < $endDay; $periodStart = $group->end($periodStart)) {
            $periodStarts[] = $periodStart;
        }
        $tickStep = self::tickStep($group, \count($periodStarts));
        $xTicks = [];
        foreach ($periodStarts as $index => $periodStart) {
            if (0 === $index % $tickStep) {
                $centre = (self::daysBetween($firstDay, $periodStart) + self::daysBetween($firstDay, $group->end($periodStart))) / 2;
                $xTicks[] = new CostChartTick(round(self::PLOT_LEFT + $centre * $dayWidth, 2), day: $periodStart);
            }
        }

        return new self(
            bars: $bars,
            slots: $slots,
            series: array_map(
                static fn (int $slot, ?string $key): CostChartSeries => new CostChartSeries($key, $slot),
                array_keys($usedSlots),
                array_values($usedSlots),
            ),
            yTicks: $yTicks,
            yTickDecimals: $step >= 1_000_000 ? 0 : max(2, (int) ceil(-log10($step / 1_000_000))),
            xTicks: $xTicks,
            longSpan: $dayCount > 366,
            group: $group,
            medianMicros: $medianMicros,
            medianY: round(self::BASELINE - $medianMicros / $topMicros * $plotHeight, 2),
        );
    }

    /** Dollars, for the currency filter. */
    public function median(): float
    {
        return $this->medianMicros / 1_000_000;
    }

    /** True when a narrow bar stands for more than one card, so it carries a count mark. */
    public function hasCountMark(): bool
    {
        return array_any($this->bars, static fn (CostChartBar $bar): bool => !$bar->isWide() && $bar->cardCount() > 1);
    }

    public function hasEstimate(): bool
    {
        return array_any($this->bars, static fn (CostChartBar $bar): bool => $bar->estimated);
    }

    public function hasPartial(): bool
    {
        return array_any($this->bars, static fn (CostChartBar $bar): bool => $bar->partial);
    }

    /** Zero for a key that shares the grey. */
    public function slotOf(string $key): int
    {
        return $this->slots[$key] ?? 0;
    }

    /**
     * Keys keep their place in the sorted list of the project, so a colour
     * follows its rule or model whatever the filters show.
     *
     * @param list<string> $keys
     *
     * @return array<string, int>
     */
    private static function slots(array $keys): array
    {
        $slots = [];
        $named = \count($keys) > self::PALETTE_SIZE ? self::PALETTE_SIZE - 1 : self::PALETTE_SIZE;
        foreach (array_slice($keys, 0, $named) as $index => $key) {
            $slots[$key] = $index + 1;
        }

        return $slots;
    }

    /** A step of 1, 2 or 5 times a power of ten, in millionths of a dollar. */
    private static function niceStep(float $raw): int
    {
        $magnitude = 10 ** floor(log10(max(1.0, $raw)));
        $fraction = $raw / $magnitude;
        $nice = match (true) {
            $fraction <= 1 => 1,
            $fraction <= 2 => 2,
            $fraction <= 5 => 5,
            default => 10,
        };

        return max(1, (int) round($nice * $magnitude));
    }

    /** A day keeps a gap to its neighbour. A week or a month leaves room around its bar. */
    private static function barWidth(float $slotWidth, CostGroup $group): float
    {
        if ($slotWidth < self::MIN_BAR_WIDTH + self::GAP) {
            return min($slotWidth, self::MIN_BAR_WIDTH);
        }

        return CostGroup::Day === $group
            ? min(self::MAX_DAY_BAR_WIDTH, $slotWidth - self::GAP)
            : min(self::MAX_BAR_WIDTH, max(self::MIN_BAR_WIDTH, $slotWidth * self::BAR_SHARE));
    }

    /** How many periods one label of the time axis covers. */
    private static function tickStep(CostGroup $group, int $periodCount): int
    {
        $steps = match ($group) {
            CostGroup::Day => [1, 2, 7, 14, 30, 61, 91, 182, 365],
            CostGroup::Week => [1, 2, 4, 8, 13, 26, 52],
            CostGroup::Month => [1, 2, 3, 6, 12, 24],
        };
        foreach ($steps as $step) {
            if (ceil($periodCount / $step) <= self::MAX_TIME_TICKS) {
                return $step;
            }
        }

        return (int) ceil($periodCount / self::MAX_TIME_TICKS);
    }

    private static function day(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTime(0, 0);
    }

    private static function daysBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->format('%r%a');
    }

    /** A rectangle, with the top corners rounded when it ends the bar. The baseline end stays square. */
    private static function path(float $x, float $top, float $width, float $height, bool $roundTop): string
    {
        $radius = $roundTop ? min(self::RADIUS, $width / 2, $height) : 0.0;
        $right = $x + $width;
        $bottom = $top + $height;

        if ($radius <= 0.0) {
            return \sprintf('M%s %sV%sH%sV%sZ', self::number($x), self::number($bottom), self::number($top), self::number($right), self::number($bottom));
        }

        return \sprintf(
            'M%s %sV%sQ%s %s %s %sH%sQ%s %s %s %sV%sZ',
            self::number($x),
            self::number($bottom),
            self::number($top + $radius),
            self::number($x),
            self::number($top),
            self::number($x + $radius),
            self::number($top),
            self::number($right - $radius),
            self::number($right),
            self::number($top),
            self::number($right),
            self::number($top + $radius),
            self::number($bottom),
        );
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
