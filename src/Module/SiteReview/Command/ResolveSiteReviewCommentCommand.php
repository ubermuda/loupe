<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewComment;

final readonly class ResolveSiteReviewCommentCommand
{
    /**
     * @param ?string $trigger what resolved the comment when a person did not click resolve, recorded as given
     * @param ?string $actor   who caused the trigger, recorded as given
     */
    public function __construct(
        public SiteReviewComment $comment,
        public ?string $trigger = null,
        public ?string $actor = null,
    ) {
    }
}
