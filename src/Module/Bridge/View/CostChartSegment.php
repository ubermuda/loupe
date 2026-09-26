<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

/** One painted part of a bar, as an SVG path. */
final readonly class CostChartSegment
{
    public function __construct(
        /** The palette slot, from 1, or zero for the other keys. */
        public int $slot,
        public string $path,
        /** True when the part is an estimate, or has no price. */
        public bool $estimated,
    ) {
    }
}
