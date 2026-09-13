<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;

/** The caller has already checked that the user may view the project. */
final readonly class AuthorizeBoardRefreshCommand
{
    public function __construct(
        public Project $project,
    ) {
    }
}
