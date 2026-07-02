<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\Site;

final readonly class SubmitReviewCommand
{
    public function __construct(
        public Site $site,
    ) {
    }
}
