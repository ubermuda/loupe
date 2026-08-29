<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewComment;

final readonly class MarkSiteReviewCommentsAddressedCommand
{
    /**
     * @param list<SiteReviewComment> $comments the batch, already resolved and authorized by the caller
     */
    public function __construct(
        public array $comments,
    ) {
    }
}
