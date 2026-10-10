<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

final readonly class StartGitHubConnectCommand
{
    public function __construct(
        public ?string $projectId,
        public ?string $origin,
        public string $redirectUri,
    ) {
    }
}
