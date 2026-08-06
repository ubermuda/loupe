<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

final readonly class CheckInviteTokenView
{
    public function __construct(
        public bool $valid,
    ) {
    }
}
