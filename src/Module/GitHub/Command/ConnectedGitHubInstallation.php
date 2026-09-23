<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\GitHub\Entity\GitHubInstallation;

final readonly class ConnectedGitHubInstallation
{
    public function __construct(
        public GitHubInstallation $installation,
        public int $refusedRepositories,
    ) {
    }
}
