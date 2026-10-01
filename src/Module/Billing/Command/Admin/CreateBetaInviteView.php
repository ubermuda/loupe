<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Module\Billing\Entity\BetaInvite;

final readonly class CreateBetaInviteView
{
    public function __construct(
        public BetaInvite $invite,
        /** The database holds only the hash, so this is the one chance to show the link. */
        public string $token,
    ) {
    }
}
