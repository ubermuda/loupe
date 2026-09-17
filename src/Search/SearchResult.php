<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchResult
{
    public function __construct(
        public string $title,
        public string $url,
        public string $kind,
    ) {
    }
}
