<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Stats\ProjectStats;
use App\Module\Project\Stats\ProjectStatsProviderInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** Board's contribution to the projects list: how many cards each project still has open. */
final readonly class CardProjectStatsProvider implements ProjectStatsProviderInterface
{
    public function __construct(
        private CardRepository $cards,
        private FeatureFlagService $featureFlags,
    ) {
    }

    #[\Override]
    public function statsFor(array $projects): array
    {
        // An operator who has not switched the board on has no page to open the
        // count from, so the figure is noise. The export is the other way round
        // and always reports the cards the account holds.
        if (!$this->featureFlags->isEnabled(BoardInstallFlags::FLAG_BOARD_ENABLED)) {
            return [];
        }

        return array_map(
            static fn (int $count): ProjectStats => new ProjectStats(openCardCount: $count),
            $this->cards->countOpenByProjects($projects),
        );
    }
}
