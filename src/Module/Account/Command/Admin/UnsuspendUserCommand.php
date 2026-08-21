<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Entity\User;

final readonly class UnsuspendUserCommand
{
    public function __construct(
        public User $target,
        public User $actor,
    ) {
    }
}
