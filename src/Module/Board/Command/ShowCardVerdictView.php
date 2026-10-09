<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;

final readonly class ShowCardVerdictView
{
    /**
     * @param list<VerdictPullRequestOption>                                       $pullRequests
     * @param list<array{id: string, url: string, body: string, anchorCount: int}> $notes
     * @param list<CardVerdictDelivery>                                            $latestDeliveries
     * @param array<string, list<VerdictActionOption>>                             $actions          keyed by verdict kind
     */
    public function __construct(
        public Card $card,
        public array $pullRequests,
        public array $notes,
        public string $connection,
        public ?CardVerdict $latest,
        public array $latestDeliveries,
        public array $actions,
    ) {
    }
}
