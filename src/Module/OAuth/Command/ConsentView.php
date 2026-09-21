<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Project\Entity\Project;

final readonly class ConsentView
{
    /**
     * @param list<Project> $projects      the projects the user owns
     * @param ?string       $clientHost    the host of a client_id URL, the only fact
     *                                     Loupe checked; the name is then self-declared
     * @param ?string       $clientIdUrl   the whole client_id, shown when Loupe
     *                                     vouches for no part of it
     * @param ?string       $clientIconUrl that host's icon, served by Loupe
     */
    public function __construct(
        public string $clientName,
        public ?string $clientHost,
        public ?string $clientIdUrl,
        public ?string $clientIdPath,
        public bool $clientTrusted,
        public ?string $clientIconUrl,
        public ApiTokenScope $scope,
        public bool $needsProject,
        public string $redirectOrigin,
        public bool $loopbackOnly,
        public array $projects,
    ) {
    }
}
