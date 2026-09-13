<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Project\Repository\ProjectRepository;

final readonly class ListProjectColumnsHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private BoardColumnRepository $columns,
    ) {
    }

    public function __invoke(ListProjectColumnsCommand $command): ListProjectColumnsView
    {
        // The lookup is owner-scoped, so another user's project reads as absent.
        $project = $this->projects->findOneByIdOrNameForOwner($command->handle, $command->owner);
        if (null === $project) {
            return new ListProjectColumnsView(null, []);
        }

        return new ListProjectColumnsView($project, $this->columns->findForProject($project));
    }
}
