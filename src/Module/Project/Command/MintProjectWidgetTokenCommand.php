<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;

final readonly class MintProjectWidgetTokenCommand
{
    public function __construct(
        public Project $project,
    ) {
    }
}
