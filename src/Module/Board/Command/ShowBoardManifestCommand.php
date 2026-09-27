<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowBoardManifestCommand
{
    public function __construct(
        public Project $project,
    ) {
    }
}
