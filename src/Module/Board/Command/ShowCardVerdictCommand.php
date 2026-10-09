<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;

final readonly class ShowCardVerdictCommand
{
    public function __construct(
        public Project $project,
        public string $cardId,
        public User $reviewer,
    ) {
    }
}
