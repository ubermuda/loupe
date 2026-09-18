<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\SiteReview\Entity\SiteReviewComment;

final readonly class AttachSiteReviewCommentCommand
{
    public function __construct(
        public SiteReviewComment $comment,
        public Card $card,
    ) {
    }
}
