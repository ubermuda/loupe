<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;

final readonly class ListCardHoldsCommand
{
    public function __construct(
        public User $user,
    ) {
    }
}
