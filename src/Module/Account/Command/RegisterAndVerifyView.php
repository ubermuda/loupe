<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;

final readonly class RegisterAndVerifyView
{
    public function __construct(
        public ?User $user,
        public ?DomainErrors $errors,
    ) {
    }
}
