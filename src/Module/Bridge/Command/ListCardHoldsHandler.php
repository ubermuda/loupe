<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\CardHoldRepository;

final readonly class ListCardHoldsHandler
{
    public function __construct(
        private CardHoldRepository $cardHolds,
    ) {
    }

    public function __invoke(ListCardHoldsCommand $command): ListCardHoldsView
    {
        return new ListCardHoldsView($this->cardHolds->findByOwner($command->user));
    }
}
