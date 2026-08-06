<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewEventRepository;
use Ubermuda\AdminBundle\Listing\ListPagePagination;

final readonly class ListSiteReviewOutboxHandler
{
    public const int PER_PAGE = 20;
    public const array ALLOWED_SORTS = ['createdAt', 'publishAttempts', 'nextAttemptAt'];

    public function __construct(
        private SiteReviewEventRepository $siteReviewEvents,
        private ListPagePagination $pagination,
    ) {
    }

    public function __invoke(ListSiteReviewOutboxCommand $command): ListSiteReviewOutboxView
    {
        // Matching the filter against the projects that actually have stuck
        // events, instead of looking the id up, means an unknown or malformed
        // id widens to "all projects" rather than 404-ing or leaking whether a
        // project exists.
        $projectsWithUnsent = $this->siteReviewEvents->findProjectsWithUnsent();
        $project = array_find(
            $projectsWithUnsent,
            static fn (Project $candidate): bool => (string) $candidate->id === $command->requestedProjectId,
        );

        $filters = null !== $project ? ['project' => $command->requestedProjectId] : [];
        $total = $this->siteReviewEvents->countUnsent($project);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        return new ListSiteReviewOutboxView(
            events: $this->siteReviewEvents->findUnsentPaginated(
                $project,
                $command->page,
                self::PER_PAGE,
                $command->sort,
                $command->dir,
            ),
            projects: $projectsWithUnsent,
            selectedProjectId: null !== $project ? $command->requestedProjectId : '',
            total: $total,
            totalPages: $totalPages,
            pageList: $this->pagination->buildPageList($command->page, $totalPages),
            filters: $filters,
            clampedPage: $this->pagination->clampPage(
                'site_review_events',
                $command->page,
                $total,
                self::PER_PAGE,
                $filters,
            ),
        );
    }
}
