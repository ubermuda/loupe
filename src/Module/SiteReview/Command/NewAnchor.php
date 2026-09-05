<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

/**
 * One element a new comment points at. The Api request DTO maps onto this, so
 * the handler never depends on the shape of the HTTP payload.
 */
final readonly class NewAnchor
{
    public function __construct(
        public string $selector,
        public string $text,
        public ?string $quote = null,
        public ?string $quotePrefix = null,
        public ?string $quoteSuffix = null,
    ) {
    }
}
