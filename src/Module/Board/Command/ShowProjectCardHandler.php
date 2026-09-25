<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Repository\ProjectRepository;

final readonly class ShowProjectCardHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private CardRepository $cards,
    ) {
    }

    public function __invoke(ShowProjectCardCommand $command): ProjectCardView
    {
        // The lookup is owner-scoped, so another user's project reads as absent.
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return new ProjectCardView(null, null);
        }

        $projectId = $project->id ?? throw new \LogicException('Project has no id.');

        return new ProjectCardView($project, $this->cards->findOneByIdAndProjectId($command->cardId, (string) $projectId));
    }
}
