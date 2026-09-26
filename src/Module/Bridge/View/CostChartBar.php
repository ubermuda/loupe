<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

/** The bar of one period, in the coordinates of the chart's view box. Its height is the average cost of its cards. */
final readonly class CostChartBar
{
    /**
     * @param non-empty-list<CardCost> $cards        the cards that finished in the period, oldest first
     * @param array<string, int>       $partAverages the average of each key over the cards, in millionths of a dollar
     * @param list<CostChartSegment>   $segments     bottom first, empty for a stub
     */
    public function __construct(
        public \DateTimeImmutable $periodStart,
        public array $cards,
        /** Millionths of a dollar. */
        public int $averageMicros,
        public array $partAverages,
        public float $x,
        public float $width,
        /** The y of the top edge. */
        public float $top,
        public array $segments,
        /** The outline of the whole bar, for a stub or the hatch of an estimate. */
        public string $outline,
        /** The hover and focus target: the whole period, as tall as the plot. */
        public float $hitX,
        public float $hitWidth,
        /** True when a card of the period has an estimate or a row with no price. */
        public bool $estimated,
        /** True when a card of the period has runs with no usage. */
        public bool $partial,
    ) {
    }

    /** A period whose cards have no priced part gets a neutral stub, so its marks have a place. */
    public function isStub(): bool
    {
        return [] === $this->segments;
    }

    /** A wide bar has room for the card count and the average above it. */
    public function isWide(): bool
    {
        return $this->width >= CostChart::WIDE_BAR_WIDTH;
    }

    public function cardCount(): int
    {
        return \count($this->cards);
    }

    /** Dollars, for the currency filter. */
    public function average(): float
    {
        return $this->averageMicros / 1_000_000;
    }

    public function centre(): float
    {
        return round($this->x + $this->width / 2, 2);
    }
}
