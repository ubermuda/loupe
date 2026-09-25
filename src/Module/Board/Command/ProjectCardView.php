<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;

/** One card of one of the owner's projects. The card is null when the project holds no card by that id. */
final readonly class ProjectCardView
{
    public function __construct(
        public ?Project $project,
        public ?Card $card,
    ) {
    }
}
