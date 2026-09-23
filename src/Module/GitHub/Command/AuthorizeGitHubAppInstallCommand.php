<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\GitHub\Service\PendingGitHubInstall;

final readonly class AuthorizeGitHubAppInstallCommand
{
    public function __construct(
        public PendingGitHubInstall $pending,
        public ?string $state,
        public int $installationId,
        public string $redirectUri,
    ) {
    }
}
