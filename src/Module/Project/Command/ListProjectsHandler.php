<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Stats\ProjectStats;
use App\Module\Project\Stats\ProjectStatsProviderInterface;
use App\Module\Project\View\ProjectListItem;
use App\Utils\PageList;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ListProjectsHandler
{
    public const int PER_PAGE = 20;

    /** @param iterable<ProjectStatsProviderInterface> $statsProviders */
    public function __construct(
        private ProjectRepository $projects,

        #[AutowireIterator('app.project_stats_provider')]
        private iterable $statsProviders,
    ) {
    }

    public function __invoke(ListProjectsCommand $command): ListProjectsView
    {
        $paginator = $this->projects->findPaginatedByOwner($command->owner, $command->page, self::PER_PAGE);
        $total = count($paginator);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        $projects = iterator_to_array($paginator, false);

        // Each module counts its own rows for the whole page at once — one
        // grouped query per provider rather than one per project per figure,
        // which is what made this list forty queries.
        $stats = [];
        foreach ($this->statsProviders as $provider) {
            foreach ($provider->statsFor($projects) as $projectId => $contribution) {
                $stats[$projectId] = ($stats[$projectId] ?? new ProjectStats())->merge($contribution);
            }
        }

        return new ListProjectsView(
            items: array_map(
                static function (Project $project) use ($stats): ProjectListItem {
                    $projectStats = $stats[(string) $project->id] ?? new ProjectStats();

                    return new ProjectListItem(
                        project: $project,
                        documentCount: $projectStats->documentCount,
                        commentCount: $projectStats->commentCount,
                        openCount: $projectStats->openCommentCount,
                    );
                },
                $projects,
            ),
            totalPages: $totalPages,
            pageList: PageList::build($command->page, $totalPages),
            clampedPage: PageList::clampedPage($command->page, $total, self::PER_PAGE),
        );
    }
}
