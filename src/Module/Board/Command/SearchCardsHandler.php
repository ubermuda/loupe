<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\CardRepository;

final readonly class SearchCardsHandler
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    public function __invoke(SearchCardsCommand $command): SearchCardsView
    {
        return new SearchCardsView($this->cards->searchOpenForProject(
            $command->project,
            $command->query,
            $command->limit,
        ));
    }
}
