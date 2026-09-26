<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\Cost\FinishedCard;

/** What the worker runs of one finished card spent, under the filters of the cost tab. */
final readonly class CardCost
{
    /** Millionths of a dollar. */
    public int $costMicros;
    public int $inputTokens;
    public int $outputTokens;
    public int $cacheReadTokens;
    public int $cacheWriteTokens;
    public bool $estimated;

    /** @param non-empty-list<CostPart> $parts */
    public function __construct(
        public FinishedCard $card,
        public array $parts,
        /** Closed worker runs that started and reported no usage. */
        public int $partialRuns,
    ) {
        $this->costMicros = array_sum(array_map(static fn (CostPart $part): int => $part->costMicros, $parts));
        $this->inputTokens = array_sum(array_map(static fn (CostPart $part): int => $part->inputTokens, $parts));
        $this->outputTokens = array_sum(array_map(static fn (CostPart $part): int => $part->outputTokens, $parts));
        $this->cacheReadTokens = array_sum(array_map(static fn (CostPart $part): int => $part->cacheReadTokens, $parts));
        $this->cacheWriteTokens = array_sum(array_map(static fn (CostPart $part): int => $part->cacheWriteTokens, $parts));
        $this->estimated = array_any($parts, static fn (CostPart $part): bool => $part->estimated);
    }

    /** Dollars, for the currency filter. */
    public function cost(): float
    {
        return $this->costMicros / 1_000_000;
    }
}
