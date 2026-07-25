<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;

final readonly class CompleteWizardCommand
{
    public function __construct(
        public User $user,
    ) {
    }
}
