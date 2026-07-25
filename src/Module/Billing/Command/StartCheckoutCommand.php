<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Account\Entity\User;

final readonly class StartCheckoutCommand
{
    public function __construct(
        public User $user,
        /** @phpstan-var non-empty-string */
        public string $successUrl,
        /** @phpstan-var non-empty-string */
        public string $cancelUrl,
    ) {
    }
}
