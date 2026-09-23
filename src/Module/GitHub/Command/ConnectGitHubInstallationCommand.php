<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

final readonly class ConnectGitHubInstallationCommand
{
    public function __construct(
        public string $projectId,
        public int $installationId,
        public string $code,
        public string $codeVerifier,
        public string $redirectUri,
    ) {
    }
}
