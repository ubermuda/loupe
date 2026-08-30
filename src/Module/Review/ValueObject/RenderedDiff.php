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
 */
final readonly class RenderedDiff
{
    public function __construct(
        public string $html,
        public int $changeCount,
    ) {
    }
}
