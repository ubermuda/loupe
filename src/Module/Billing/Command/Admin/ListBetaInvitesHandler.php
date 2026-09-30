<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Module\Billing\Repository\BetaInviteRepository;

final readonly class ListBetaInvitesHandler
{
    public function __construct(
        private BetaInviteRepository $betaInvites,
    ) {
    }

    public function __invoke(ListBetaInvitesCommand $command): ListBetaInvitesView
    {
        return new ListBetaInvitesView($this->betaInvites->findAllNewestFirst());
    }
}
