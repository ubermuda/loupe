<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

final readonly class CreateAdminUserCommand
{
    public function __construct(
        /** @phpstan-var non-empty-string */
        public string $email,
        /** @phpstan-var non-empty-string */
        public string $plainPassword,
        /** Null derives a free one from the email's local part. */
        /** Null falls back to the email's local part. */
        public ?string $fullName = null,
    ) {
    }
}
