<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewComment;

final readonly class ResolveSiteReviewCommentCommand
{
    public function __construct(
        public SiteReviewComment $comment,
    ) {
    }
}
