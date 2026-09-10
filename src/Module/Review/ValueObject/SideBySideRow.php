<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * One block of a comparison, as the two versions each hold it.
 *
 * A null side is a block the version does not have, which the page draws as an
 * empty slot rather than closing up. Both sides read the same HTML when the
 * revision left the block alone.
 */
final readonly class SideBySideRow
{
    public function __construct(
        public ?string $oldHtml,
        public ?string $newHtml,
    ) {
    }
}
