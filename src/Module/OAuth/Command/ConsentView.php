<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Project\Entity\Project;

final readonly class ConsentView
{
    /** @param list<Project> $projects the projects the user owns */
    public function __construct(
        public string $clientName,
        public ApiTokenScope $scope,
        public bool $needsProject,
        public string $redirectOrigin,
        public bool $loopbackOnly,
        public array $projects,
    ) {
    }
}
