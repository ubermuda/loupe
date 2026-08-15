<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewComment;

final readonly class ShowPendingCommentsView
{
    /** @param list<SiteReviewComment> $comments */
    public function __construct(
        public array $comments,
    ) {
    }
}
