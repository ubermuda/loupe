<?php

declare(strict_types=1);

namespace App\Search\Command;

use App\Search\SearchResults;

final readonly class SearchOwnedProjectsView
{
    public function __construct(
        public string $query,
        public int $page,
        public SearchResults $results,
    ) {
    }
}
