<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

final readonly class ListSitesView
{
    /** @param list<ListedSite> $sites */
    public function __construct(
        public array $sites,
    ) {
    }
}
