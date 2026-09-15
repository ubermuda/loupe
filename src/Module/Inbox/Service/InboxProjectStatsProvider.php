<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Stats\ProjectStats;
use App\Module\Project\Stats\ProjectStatsProviderInterface;

/** Inbox's contribution to the projects list: how many items each project still has open. */
final readonly class InboxProjectStatsProvider implements ProjectStatsProviderInterface
{
    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxAvailability $inbox,
    ) {
    }

    #[\Override]
    public function statsFor(array $projects): array
    {
        // With the inbox off there is no page to open the count from.
        if (!$this->inbox->isEnabled()) {
            return [];
        }

        return array_map(
            static fn (int $count): ProjectStats => new ProjectStats(openInboxItemCount: $count),
            $this->inboxItems->countOpenByProjects($projects),
        );
    }
}
