<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Account\Entity\User;

final readonly class ShowSubscribeCommand
{
    public function __construct(
        public User $user,
    ) {
    }
}
