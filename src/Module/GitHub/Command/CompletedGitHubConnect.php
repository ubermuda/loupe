<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

final readonly class CompletedGitHubConnect
{
    /** @param ?string $targetOrigin the one origin the page may tell, or null to tell nobody */
    public function __construct(
        public string $login,
        public ?string $targetOrigin,
    ) {
    }
}
