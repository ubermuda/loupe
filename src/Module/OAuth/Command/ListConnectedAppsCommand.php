<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\User;

final readonly class ListConnectedAppsCommand
{
    public function __construct(
        public User $user,
    ) {
    }
}
