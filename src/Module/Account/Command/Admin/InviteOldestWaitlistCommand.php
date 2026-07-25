<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

final readonly class InviteOldestWaitlistCommand
{
    public function __construct(
        public int $count,
    ) {
    }
}
