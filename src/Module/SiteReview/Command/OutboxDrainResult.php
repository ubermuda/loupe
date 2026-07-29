<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

/** Outcome of one drain pass over the site-review outbox. */
final readonly class OutboxDrainResult
{
    public function __construct(
        public int $published,
        public int $failed,
    ) {
    }
}
