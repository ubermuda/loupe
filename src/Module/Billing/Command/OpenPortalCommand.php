<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Account\Entity\User;

final readonly class OpenPortalCommand
{
    public function __construct(
        public User $user,
        /** @phpstan-var non-empty-string */
        public string $returnUrl,
    ) {
    }
}
