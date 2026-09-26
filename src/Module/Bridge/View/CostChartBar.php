<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

/** The bar of one card, in the coordinates of the chart's view box. */
final readonly class CostChartBar
{
    /** @param list<CostChartSegment> $segments bottom first, empty for a stub */
    public function __construct(
        public CardCost $cost,
        public float $x,
        public float $width,
        /** The y of the top edge. */
        public float $top,
        public array $segments,
        /** The outline of the whole bar, for a stub or a hatch over the whole bar. */
        public string $outline,
        /** The hover and focus target, wider than a thin bar. */
        public float $hitX,
        public float $hitWidth,
    ) {
    }

    /** A card whose cost rounds to under two pixels gets a neutral stub, so its marks have a place. */
    public function isStub(): bool
    {
        return [] === $this->segments;
    }

    /** A part with no price paints nothing, so its estimate mark falls to the whole bar. */
    public function hasHiddenEstimate(): bool
    {
        return $this->cost->estimated && !$this->isStub() && !array_any($this->segments, static fn (CostChartSegment $segment): bool => $segment->estimated);
    }

    public function centre(): float
    {
        return round($this->x + $this->width / 2, 2);
    }
}
