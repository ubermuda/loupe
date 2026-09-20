<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;

final readonly class UpdateProjectAllowedOriginsCommand
{
    public function __construct(
        public Project $project,
        /** One origin per line, as the owner typed them. */
        public string $origins,
    ) {
    }
}
