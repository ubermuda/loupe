<?php

namespace App\Module\Account\Command;

final readonly class RegisterUserCommand
{
    public function __construct(
        public string $username,
        public string $fullName,
        /** @phpstan-var non-empty-string */
        public string $email,
        public string $plainPassword,
    ) {
    }
}
