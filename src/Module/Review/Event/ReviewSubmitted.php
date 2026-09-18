<?php

declare(strict_types=1);

namespace App\Module\Review\Event;

use App\Module\Review\Entity\Review;

/** Dispatched inside the verdict transaction, with the project and document locked. */
final readonly class ReviewSubmitted
{
    public function __construct(
        public Review $review,
    ) {
    }
}
