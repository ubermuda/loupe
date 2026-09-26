<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

/** What every run of one card spent, including runs the retention sweep deleted. */
final readonly class CardUsageTotal
{
    public function __construct(
        /** False when no run of the card reported usage. */
        public bool $known,
        /** Null when usage rows exist and none of them has a price. */
        public ?string $costUsd = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
        /** Closed worker runs that started and reported no usage. */
        public int $partialRuns = 0,
        /** True when a row is an estimate, or a row has no price. */
        public bool $estimated = false,
    ) {
    }

    /** English-only, like the duration of a run, such as `45.3k` or `1.2M`. */
    public static function compact(int $count): string
    {
        if ($count < 1000) {
            return (string) $count;
        }

        // The largest unit that rounds to at least 1, so 999,960 reads 1M and not 1000k.
        foreach ([1_000_000_000 => 'B', 1_000_000 => 'M', 1_000 => 'k'] as $unit => $suffix) {
            $scaled = round($count / $unit, 1);
            if ($scaled >= 1) {
                return rtrim(rtrim(number_format($scaled, 1, '.', ''), '0'), '.').$suffix;
            }
        }

        return (string) $count;
    }
}
