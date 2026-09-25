<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Event;

use App\Module\SiteReview\Entity\SiteReviewComment;

/**
 * Dispatched inside AddCommentHandler's transaction, after the comment has an
 * id. A listener that persists rows needs no flush of its own, because the
 * transaction flushes again when it closes.
 *
 * The event class is the module's public API, the same contract ProjectDeleting
 * carries. SiteReview does not know who listens, and a listener must never
 * throw: anything it raises aborts the comment save it was told about.
 *
 * Nothing listens. The caller that saves a comment together with its card
 * writes the link itself, inside the same transaction.
 */
final readonly class SiteReviewCommentCreated
{
    public function __construct(
        public SiteReviewComment $comment,
    ) {
    }
}
