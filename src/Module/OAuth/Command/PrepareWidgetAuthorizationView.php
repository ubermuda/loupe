<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Project\Entity\Project;

final readonly class PrepareWidgetAuthorizationView
{
    public function __construct(
        public Project $project,
        public string $origin,
    ) {
    }
}
