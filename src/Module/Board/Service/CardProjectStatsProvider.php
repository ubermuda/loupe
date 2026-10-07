<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Stats\ProjectStats;
use App\Module\Project\Stats\ProjectStatsProviderInterface;

final readonly class CardProjectStatsProvider implements ProjectStatsProviderInterface
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    #[\Override]
    public function statsFor(array $projects): array
    {
        return array_map(
            static fn (array $counts): ProjectStats => new ProjectStats(openCardCount: $counts['open'], completedCardCount: $counts['completed']),
            $this->cards->countByProjects($projects),
        );
    }
}
