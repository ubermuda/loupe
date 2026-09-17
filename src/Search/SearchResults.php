<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchResults
{
    /** @param list<SearchResult> $items */
    public function __construct(
        public array $items = [],
        public bool $hasMore = false,
    ) {
    }
}
