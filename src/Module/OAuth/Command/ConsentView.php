<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Project\Entity\Project;

final readonly class ConsentView
{
    /**
     * @param list<Project> $projects   the projects the user owns
     * @param ?string       $clientHost the host of a client_id URL, the only fact
     *                                  Loupe checked; the name is then self-declared
     */
    public function __construct(
        public string $clientName,
        public ?string $clientHost,
        public ApiTokenScope $scope,
        public bool $needsProject,
        public string $redirectOrigin,
        public bool $loopbackOnly,
        public array $projects,
    ) {
    }
}
