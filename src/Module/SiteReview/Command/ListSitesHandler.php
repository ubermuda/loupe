<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Repository\ProjectRepository;

final readonly class ListSitesHandler
{
    public function __construct(
        private ProjectRepository $projects,
    ) {
    }

    public function __invoke(ListSitesCommand $command): ListSitesView
    {
        return new ListSitesView(
            sites: $this->projects->findByOwner($command->owner),
        );
    }
}
