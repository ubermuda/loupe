<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * A maximal run of consecutive diff lines that are all changed, or all
 * unchanged. Consecutive groups always alternate, so a reviewer jumping between
 * changed groups visits every edit exactly once.
 */
final readonly class DiffGroup
{
    /** @param list<DiffLine> $lines */
    public function __construct(
        public bool $changed,
        public array $lines,
    ) {
    }
}
