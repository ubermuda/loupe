<?php

namespace App\Module\Account\Command;

final readonly class RegisterUserCommand
{
    public function __construct(
        /** @phpstan-var non-empty-string */
        public string $email,
        public string $fullName,
        public string $plainPassword,
        /**
         * Plain registration-pass token, if any. Redeemed whenever it is valid;
         * when the gate is closed, only a redeemed pass bypasses it.
         */
        public ?string $inviteToken = null,
    ) {
    }
}
