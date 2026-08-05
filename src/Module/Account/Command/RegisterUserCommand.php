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
         * Plain waitlist-invite token, if any. Ignored while registration is
         * open; when the gate is closed, only a valid token bypasses it.
         */
        public ?string $inviteToken = null,
    ) {
    }
}
