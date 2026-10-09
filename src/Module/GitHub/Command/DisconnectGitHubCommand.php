<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Account\Entity\User;

final readonly class DisconnectGitHubCommand
{
    public function __construct(
        public User $user,
    ) {
    }
}
