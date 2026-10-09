<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * A diff rendered as the document it describes, with its changes marked and
 * numbered.
 *
 * `changeCount` counts the jump targets in `html`, which is what the reviewer
 * moves between. It is not DocumentDiff's line-group count: a rendered diff has
 * no lines, so the two partition the same edit differently.
 *
 * `changesByHeadingId` counts those changes per heading id, for the headings
 * that hold one. A change before the first heading counts under none.
 */
final readonly class RenderedDiff
{
    /** @param array<string, int> $changesByHeadingId */
    public function __construct(
        public string $html,
        public int $changeCount,
        public array $changesByHeadingId = [],
    ) {
    }
}
