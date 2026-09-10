<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * A rendered diff paired into two columns, one row per top-level block.
 *
 * The rows are in document order, so the page draws them as one grid and each
 * pair shares a row of it.
 */
final readonly class SideBySideDiff
{
    /** @param list<SideBySideRow> $rows */
    public function __construct(
        public array $rows,
    ) {
    }
}
