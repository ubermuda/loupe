<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;

final readonly class ShowCardPlacementCommand
{
    public function __construct(
        public Project $project,
        /** Null when the card no longer exists. */
        public ?Card $card,
    ) {
    }
}
