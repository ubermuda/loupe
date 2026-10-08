<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Service\PendingGitHubConnect;

final readonly class CompleteGitHubConnectCommand
{
    public function __construct(
        public User $user,
        public PendingGitHubConnect $pending,
        public ?string $state,
        public ?string $code,
        public string $redirectUri,
    ) {
    }
}
