<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Account\Entity\User;

final readonly class SeedBillingStateCommand
{
    public function __construct(
        public bool $billingEnabled,
        public string $state,
        public ?User $user,
        public bool $sweep,
    ) {
    }
}
