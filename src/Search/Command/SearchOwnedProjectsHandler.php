<?php

declare(strict_types=1);

namespace App\Search\Command;

use App\Module\Project\Repository\ProjectRepository;
use App\Search\SearchProviderInterface;
use App\Search\SearchResult;
use App\Search\SearchResults;

final readonly class SearchOwnedProjectsHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private SearchProjectHandler $search,
    ) {
    }

    public function __invoke(SearchOwnedProjectsCommand $command): SearchOwnedProjectsView
    {
        $query = trim($command->query);
        $page = min(max(1, $command->page), intdiv(\PHP_INT_MAX, SearchProviderInterface::PAGE_SIZE));
        $items = [];
        $hasMore = false;
        foreach ($this->projects->findByOwner($command->owner) as $project) {
            $view = ($this->search)(new SearchProjectCommand($project, $query, $page));
            foreach ($view->results->items as $result) {
                $projectScoped = str_starts_with($result->url, '/projects/'.(string) $project->id);
                $items[$result->url] = new SearchResult(
                    $projectScoped ? $project->name.' · '.$result->title : $result->title,
                    $result->url,
                    $result->kind,
                );
            }
            $hasMore = $hasMore || $view->results->hasMore;
        }

        return new SearchOwnedProjectsView($query, $page, new SearchResults(array_values($items), $hasMore));
    }
}
