<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * The passages a version added since the version before it.
 *
 * An empty list with no reason means the two versions differ in no added text.
 * A reason means no comparison took place, so the list is empty for that cause.
 */
final readonly class NewTextAnchors
{
    /** @param list<Anchor> $anchors */
    public function __construct(
        public array $anchors = [],
        public ?NewTextReason $reason = null,
    ) {
    }
}
