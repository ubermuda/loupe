<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

/** What became of one comment in a mark-as-addressed batch. */
enum MarkSiteReviewCommentAddressedOutcome
{
    case Addressed;
    case AlreadyAddressed;
    case AlreadyResolved;
    case NotFound;
}
