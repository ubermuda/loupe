<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Module\Project\Entity\Project;
use App\Outbox\Repository\OutboxEventRepository;
use Ubermuda\AdminBundle\Listing\ListPagePagination;

final readonly class ListOutboxHandler
{
    public const int PER_PAGE = 20;
    public const array ALLOWED_SORTS = ['createdAt', 'publishAttempts', 'nextAttemptAt'];

    public function __construct(
        private OutboxEventRepository $outboxEvents,
        private ListPagePagination $pagination,
    ) {
    }

    public function __invoke(ListOutboxCommand $command): ListOutboxView
    {
        // Matching the filter against the projects that actually have stuck
        // events, instead of looking the id up, means an unknown or malformed
        // id widens to "all projects" rather than 404-ing or leaking whether a
        // project exists.
        $projectsWithUnsent = $this->outboxEvents->findProjectsWithUnsent();
        $project = array_find(
            $projectsWithUnsent,
            static fn (Project $candidate): bool => (string) $candidate->id === $command->requestedProjectId,
        );

        $filters = null !== $project ? ['project' => $command->requestedProjectId] : [];
        $total = $this->outboxEvents->countUnsent($project);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        return new ListOutboxView(
            events: $this->outboxEvents->findUnsentPaginated(
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
                'outbox_events',
                $command->page,
                $total,
                self::PER_PAGE,
                $filters,
            ),
        );
    }
}
