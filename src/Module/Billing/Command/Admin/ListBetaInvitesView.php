<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Module\Billing\Entity\BetaInvite;

final readonly class ListBetaInvitesView
{
    /** @param list<BetaInvite> $invites */
    public function __construct(
        public array $invites,
    ) {
    }
}
