<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Repository\ProjectRepository;

final readonly class ShowProjectCardHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private CardRepository $cards,
        private CardHolds $cardHolds,
    ) {
    }

    public function __invoke(ShowProjectCardCommand $command): ProjectCardView
    {
        // The lookup is owner-scoped, so another user's project reads as absent.
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return new ProjectCardView(null, null, false);
        }

        $projectId = $project->id ?? throw new \LogicException('Project has no id.');

        $card = $this->cards->findOneByIdAndProjectId($command->cardId, (string) $projectId);
        $held = null !== $card && $this->cardHolds->isHeld($project, $card->id ?? throw new \LogicException('A stored card has an id.'));

        return new ProjectCardView($project, $card, $held);
    }
}
