<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

final readonly class CheckInviteTokenCommand
{
    public function __construct(
        public string $token,
    ) {
    }
}
