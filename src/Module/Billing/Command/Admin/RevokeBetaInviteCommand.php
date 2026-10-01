<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Module\Billing\Entity\BetaInvite;

final readonly class RevokeBetaInviteCommand
{
    public function __construct(
        public BetaInvite $invite,
    ) {
    }
}
