<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;

final readonly class MintApiTokenCommand
{
    public function __construct(
        public User $owner,
        /** @phpstan-var non-empty-string */
        public string $label,
    ) {
    }
}
