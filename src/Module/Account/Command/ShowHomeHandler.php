<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Project\Repository\ProjectRepository;

final readonly class ShowHomeHandler
{
    public function __construct(
        private ProjectRepository $projects,
    ) {
    }

    public function __invoke(ShowHomeCommand $command): ShowHomeView
    {
        return new ShowHomeView(
            projects: $this->projects->findByOwner($command->user),
        );
    }
}
