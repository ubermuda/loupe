<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

final readonly class InviteWaitlistResult
{
    public function __construct(
        public int $invited,
        public int $skipped,
    ) {
    }
}
