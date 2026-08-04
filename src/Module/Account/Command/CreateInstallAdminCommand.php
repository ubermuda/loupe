<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

final readonly class CreateInstallAdminCommand
{
    public function __construct(
        /** @phpstan-var non-empty-string */
        public string $email,
        /** @phpstan-var non-empty-string */
        public string $plainPassword,
    ) {
    }
}
