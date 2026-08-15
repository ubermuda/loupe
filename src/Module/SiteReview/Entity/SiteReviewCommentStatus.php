<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Entity;

enum SiteReviewCommentStatus: string
{
    case Pending = 'pending';
    case Addressed = 'addressed';
    case Resolved = 'resolved';
}
