<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewComment;

final readonly class ListFeedbackView
{
    /**
     * @param list<SiteReviewComment>              $feedback in project order
     * @param array<string, CardSiteReviewComment> $links    the card link of each item, keyed by comment id
     */
    public function __construct(
        public array $feedback,
        public array $links,
    ) {
    }
}
