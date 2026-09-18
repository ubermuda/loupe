<?php

declare(strict_types=1);

namespace App\Search\Command;

use App\Search\SearchProviderInterface;
use App\Search\SearchResults;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class SearchProjectHandler
{
    /** @param iterable<SearchProviderInterface> $providers */
    public function __construct(
        #[AutowireIterator('app.search_provider')]
        private iterable $providers,
    ) {
    }

    public function __invoke(SearchProjectCommand $command): SearchProjectView
    {
        $query = trim($command->query);
        $page = min(max(1, $command->page), intdiv(\PHP_INT_MAX, SearchProviderInterface::PAGE_SIZE));
        $items = [];
        $hasMore = false;
        foreach ($this->providers as $provider) {
            $results = $provider->search($command->project, $query, $page);
            array_push($items, ...$results->items);
            $hasMore = $hasMore || $results->hasMore;
        }

        return new SearchProjectView($query, $page, new SearchResults($items, $hasMore));
    }
}
