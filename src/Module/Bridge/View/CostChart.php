<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

/**
 * The cost of each finished card as a bar on a time axis, laid out in the
 * units of an SVG view box. Twig only prints these numbers.
 */
final readonly class CostChart
{
    public const int WIDTH = 960;
    public const int HEIGHT = 280;
    public const int PLOT_LEFT = 64;
    public const int PLOT_RIGHT = 944;
    public const int PLOT_TOP = 20;
    public const int BASELINE = 248;
    /** The palette holds eight colours. Past that, the last one gives way to a grey for the rest. */
    public const int PALETTE_SIZE = 8;

    private const float MAX_BAR_WIDTH = 24.0;
    private const float MIN_BAR_WIDTH = 2.0;
    private const float GAP = 2.0;
    private const float RADIUS = 4.0;
    /** A day narrower than this groups its cards by week, and a week narrower than this by month. */
    private const float MIN_PERIOD_WIDTH = 6.0;
    private const int TARGET_TICKS = 4;
    private const int MAX_DAY_TICKS = 6;
    /** Four cents, so a chart of unpriced usage still reads in cents. */
    private const int MIN_TOP_MICROS = 40_000;

    /**
     * @param list<CostChartBar>    $bars
     * @param list<CostChartSeries> $series the series the bars use, in palette order
     * @param list<CostChartTick>   $yTicks
     * @param list<CostChartTick>   $xTicks
     * @param array<string, int>    $slots  the palette slot of each key that has a colour of its own
     * @param 'day'|'week'|'month'  $period
     */
    public function __construct(
        public array $bars,
        public array $slots,
        public array $series,
        public array $yTicks,
        public int $yTickDecimals,
        public array $xTicks,
        /** True when the time axis spans more than a year, so a day label needs its year. */
        public bool $longSpan,
        /** The stretch of time that one group of bars stands in. */
        public string $period,
    ) {
    }

    /**
     * @param non-empty-list<CardCost> $cards oldest completion first
     * @param list<string>             $keys  every rule or model of the project, sorted,
     *                                        so a filter never gives a survivor a new colour
     */
    public static function build(array $cards, array $keys, \DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        $slots = self::slots($keys);
        $firstDay = self::day($from);
        $dayCount = max(1, self::daysBetween($firstDay, self::day($to)) + 1);
        $dayWidth = (self::PLOT_RIGHT - self::PLOT_LEFT) / $dayCount;
        $plotHeight = self::BASELINE - self::PLOT_TOP;

        $maxMicros = max(self::MIN_TOP_MICROS, ...array_map(static fn (CardCost $cost): int => $cost->costMicros, $cards));
        $step = self::niceStep($maxMicros / self::TARGET_TICKS);
        $topMicros = (int) (ceil($maxMicros / $step) * $step);

        $period = match (true) {
            $dayWidth >= self::MIN_PERIOD_WIDTH => 'day',
            7 * $dayWidth >= self::MIN_PERIOD_WIDTH => 'week',
            default => 'month',
        };
        if ('day' !== $period) {
            // The axis grows to whole periods, so the first and last ones are never cut short.
            $firstDay = self::periodStart($period, $firstDay);
            $dayCount = self::daysBetween($firstDay, self::periodEnd($period, self::periodStart($period, self::day($to))));
            $dayWidth = (self::PLOT_RIGHT - self::PLOT_LEFT) / $dayCount;
        }

        // Each period holds its cards in completion order, oldest period first.
        $periods = [];
        foreach ($cards as $cost) {
            $periodStart = self::periodStart($period, self::day($cost->card->completedAt));
            $start = self::daysBetween($firstDay, $periodStart);
            $periods[$start] ??= ['end' => self::daysBetween($firstDay, self::periodEnd($period, $periodStart)), 'cards' => []];
            $periods[$start]['cards'][] = $cost;
        }

        $bars = [];
        $usedSlots = [];
        foreach ($periods as $start => $group) {
            // Each card gets an equal share of its period, and its bar and hit area stay in that share.
            $share = ($group['end'] - $start) * $dayWidth / \count($group['cards']);
            $width = match (true) {
                $share >= self::MIN_BAR_WIDTH + self::GAP => min(self::MAX_BAR_WIDTH, $share - self::GAP),
                $share >= self::MIN_BAR_WIDTH => self::MIN_BAR_WIDTH,
                default => $share,
            };

            foreach ($group['cards'] as $position => $cost) {
                $shareLeft = self::PLOT_LEFT + $start * $dayWidth + $position * $share;
                $x = $shareLeft + ($share - $width) / 2;
                $height = $cost->costMicros / $topMicros * $plotHeight;
                $segments = [];
                if ($height >= self::GAP) {
                    $painted = array_values(array_filter($cost->parts, static fn (CostPart $part): bool => $part->costMicros > 0));
                    $cursor = (float) self::BASELINE;
                    foreach ($painted as $index => $part) {
                        $slot = $slots[$part->key] ?? 0;
                        $usedSlots[$slot] = $part->key;
                        $partHeight = $part->costMicros / $topMicros * $plotHeight;
                        $bottom = 0 === $index ? $cursor : $cursor - self::GAP;
                        $top = $cursor - $partHeight;
                        $cursor = $top;
                        if ($bottom - $top > 0) {
                            $segments[] = new CostChartSegment($slot, self::path($x, $top, $width, $bottom - $top, $index === \count($painted) - 1), $part->estimated);
                        }
                    }
                } else {
                    $height = self::GAP;
                }
                $top = self::BASELINE - $height;
                $hitX = round($shareLeft, 2);

                $bars[] = new CostChartBar(
                    cost: $cost,
                    x: round($x, 2),
                    width: round($width, 2),
                    top: round($top, 2),
                    segments: $segments,
                    outline: self::path($x, $top, $width, $height, true),
                    hitX: $hitX,
                    hitWidth: round(round($shareLeft + $share, 2) - $hitX, 2),
                );
            }
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

        $dayStep = self::dayStep($dayCount);
        $xTicks = [];
        for ($dayIndex = 0; $dayIndex < $dayCount; $dayIndex += $dayStep) {
            $xTicks[] = new CostChartTick(round(self::PLOT_LEFT + ($dayIndex + 0.5) * $dayWidth, 2), day: $firstDay->modify(\sprintf('+%d days', $dayIndex)));
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
            period: $period,
        );
    }

    /** Zero for a key past the palette. */
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

    private static function dayStep(int $dayCount): int
    {
        foreach ([1, 2, 7, 14, 30, 61, 91, 182, 365] as $step) {
            if (ceil($dayCount / $step) <= self::MAX_DAY_TICKS) {
                return $step;
            }
        }

        return (int) ceil($dayCount / self::MAX_DAY_TICKS);
    }

    /**
     * The first day of the period of a day. A week starts on Monday, as in ISO 8601.
     *
     * @param 'day'|'week'|'month' $period
     */
    private static function periodStart(string $period, \DateTimeImmutable $day): \DateTimeImmutable
    {
        return match ($period) {
            'day' => $day,
            'week' => $day->modify(\sprintf('-%d days', (int) $day->format('N') - 1)),
            'month' => $day->modify('first day of this month'),
        };
    }

    /**
     * The day after the last day of the period that starts on the given day.
     *
     * @param 'day'|'week'|'month' $period
     */
    private static function periodEnd(string $period, \DateTimeImmutable $start): \DateTimeImmutable
    {
        return match ($period) {
            'day' => $start->modify('+1 day'),
            'week' => $start->modify('+7 days'),
            'month' => $start->modify('first day of next month'),
        };
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
