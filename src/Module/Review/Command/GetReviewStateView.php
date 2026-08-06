<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class GetReviewStateView
{
    /** @param list<array{quote: string, prefix: string, suffix: string}> $storedAnchors */
    public function __construct(
        public ?Document $document,
        public array $storedAnchors,
    ) {
    }
}
