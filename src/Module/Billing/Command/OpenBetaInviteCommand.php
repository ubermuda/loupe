<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Account\Entity\User;

final readonly class OpenBetaInviteCommand
{
    public function __construct(
        public string $token,
        /** Null for a signed-out visitor, who redeems the token at sign-up instead. */
        public ?User $user,
    ) {
    }
}
