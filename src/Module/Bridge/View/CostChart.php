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
    private const float MIN_HIT_WIDTH = 12.0;
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

        $byDay = [];
        foreach ($cards as $cost) {
            $byDay[self::daysBetween($firstDay, self::day($cost->card->completedAt))][] = $cost;
        }

        $bars = [];
        $usedSlots = [];
        foreach ($byDay as $dayIndex => $dayCards) {
            $count = \count($dayCards);
            // A group never leaves its day: the gap gives way first, then the bar drops below its minimum width.
            $gap = self::GAP;
            $width = min(self::MAX_BAR_WIDTH, ($dayWidth - $gap * $count) / $count);
            if ($width < self::MIN_BAR_WIDTH) {
                $gap = max(0.0, ($dayWidth - self::MIN_BAR_WIDTH * $count) / $count);
                $width = ($dayWidth - $gap * $count) / $count;
            }
            $groupWidth = $count * $width + $gap * ($count - 1);
            $left = self::PLOT_LEFT + ($dayIndex + 0.5) * $dayWidth - $groupWidth / 2;
            // Bars of one day share it without overlap. A lone bar may reach past a narrow day, so it stays easy to hit.
            $hitWidth = 1 === $count ? max(self::MIN_HIT_WIDTH, $width + 2 * $gap) : $width + $gap;

            foreach ($dayCards as $position => $cost) {
                $x = $left + $position * ($width + $gap);
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

                $bars[] = new CostChartBar(
                    cost: $cost,
                    x: round($x, 2),
                    width: round($width, 2),
                    top: round($top, 2),
                    segments: $segments,
                    outline: self::path($x, $top, $width, $height, true),
                    hitX: round($x + $width / 2 - $hitWidth / 2, 2),
                    hitWidth: round($hitWidth, 2),
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
