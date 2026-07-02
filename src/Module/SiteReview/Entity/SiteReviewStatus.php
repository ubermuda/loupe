<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Entity;

enum SiteReviewStatus: string
{
    case InProgress = 'in-progress';
    case Submitted = 'submitted';
}
