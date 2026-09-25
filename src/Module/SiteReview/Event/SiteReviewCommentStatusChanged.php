<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Event;

use Symfony\Component\Uid\Uuid;

/**
 * A comment's status changed, or the comment was deleted. A delete dispatches
 * it before the remove, so a listener can still read what the comment was
 * attached to. A listener must never throw.
 */
final readonly class SiteReviewCommentStatusChanged
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $commentId,
    ) {
    }
}
