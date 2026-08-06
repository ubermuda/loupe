<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

final readonly class ListSiteReviewOutboxCommand
{
    public function __construct(
        public int $page,
        public string $sort,
        public string $dir,
        public string $requestedProjectId,
    ) {
    }
}
