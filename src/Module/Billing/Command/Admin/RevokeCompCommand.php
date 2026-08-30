<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Module\Account\Entity\User;

final readonly class RevokeCompCommand
{
    public function __construct(
        public User $target,
        public User $actor,
    ) {
    }
}
