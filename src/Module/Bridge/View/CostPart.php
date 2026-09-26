<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

/** What one rule or one model spent on one card, or the whole card without a split. */
final readonly class CostPart
{
    public function __construct(
        /** The rule name or the model, and an empty string without a split. */
        public string $key,
        /** Millionths of a dollar. */
        public int $costMicros,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens,
        public int $cacheWriteTokens,
        /** True when a row is an estimate, or a row has no price. */
        public bool $estimated,
    ) {
    }

    /** Dollars, for the currency filter. */
    public function cost(): float
    {
        return $this->costMicros / 1_000_000;
    }
}
