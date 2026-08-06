<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

final readonly class RegisterAndVerifyCommand
{
    public function __construct(
        /** @phpstan-var non-empty-string */
        public string $email,
        public string $fullName,
        public string $plainPassword,
    ) {
    }
}
