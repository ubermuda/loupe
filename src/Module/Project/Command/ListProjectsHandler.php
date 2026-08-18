<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\View\ProjectListItem;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Utils\PageList;

final readonly class ListProjectsHandler
{
    public const int PER_PAGE = 20;

    public function __construct(
        private ProjectRepository $projects,
        private DocumentRepository $documents,
        private SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    public function __invoke(ListProjectsCommand $command): ListProjectsView
    {
        $paginator = $this->projects->findPaginatedByOwner($command->owner, $command->page, self::PER_PAGE);
        $total = count($paginator);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        $projects = iterator_to_array($paginator, false);

        // Two grouped queries for the whole page rather than two per row: at 20
        // projects that was 40 round trips to render one list.
        $documentCounts = $this->documents->countActiveByProjects($projects);
        $siteReviewCounts = $this->siteReviewComments->statusCountsForProjects($projects);

        return new ListProjectsView(
            items: array_map(
                static function (Project $project) use ($documentCounts, $siteReviewCounts): ProjectListItem {
                    $counts = $siteReviewCounts[(string) $project->id];

                    return new ProjectListItem(
                        project: $project,
                        documentCount: $documentCounts[(string) $project->id] ?? 0,
                        commentCount: array_sum($counts),
                        openCount: $counts['pending'],
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
