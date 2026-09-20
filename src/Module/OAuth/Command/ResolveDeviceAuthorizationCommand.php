<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\User;

final readonly class ResolveDeviceAuthorizationCommand
{
    public function __construct(
        public string $userCode,
        public User $user,
        public bool $approved,
    ) {
    }
}
