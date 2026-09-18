<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\SiteReviewComment;

final readonly class ReplyToSiteReviewCommentCommand
{
    public function __construct(
        public SiteReviewComment $comment,
        public User $author,
        public string $body,
        public string $submissionId,
    ) {
    }
}
