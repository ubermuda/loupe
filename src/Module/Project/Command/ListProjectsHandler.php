<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\View\ProjectListItem;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Repository\SiteReviewEventRepository;
use App\Utils\PageList;

final readonly class ListProjectsHandler
{
    public const int PER_PAGE = 20;

    public function __construct(
        private ProjectRepository $projects,
        private DocumentRepository $documents,
        private SiteReviewEventRepository $siteReviewEvents,
        private SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    public function __invoke(ListProjectsCommand $command): ListProjectsView
    {
        $paginator = $this->projects->findPaginatedByOwner($command->owner, $command->page, self::PER_PAGE);
        $total = count($paginator);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        return new ListProjectsView(
            items: array_map(
                fn (Project $project): ProjectListItem => new ProjectListItem(
                    project: $project,
                    documentCount: $this->documents->countActiveByProject($project),
                    reviewCount: $this->siteReviewEvents->countForProject($project),
                    openCount: $this->siteReviewComments->countOpenForProject($project),
                ),
                iterator_to_array($paginator, false),
            ),
            totalPages: $totalPages,
            pageList: PageList::build($command->page, $totalPages),
            clampedPage: PageList::clampedPage($command->page, $total, self::PER_PAGE),
        );
    }
}
