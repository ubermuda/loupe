<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Workshop\WorkshopCardsProviderInterface;

final readonly class ShowWorkshopInMotionHandler
{
    public function __construct(
        private WorkshopCardsProviderInterface $cards,
    ) {
    }

    public function __invoke(ShowWorkshopInMotionCommand $command): WorkshopInMotionView
    {
        return new WorkshopInMotionView($command->project, $this->cards->forProject($command->project));
    }
}
