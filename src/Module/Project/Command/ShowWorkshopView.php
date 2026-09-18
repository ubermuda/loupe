<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;
use App\Module\Project\Stats\ProjectStats;
use App\Module\Project\Workshop\WorkshopAttentionItem;
use App\Module\Project\Workshop\WorkshopCard;
use App\Module\Project\Workshop\WorkshopConnection;
use App\Outbox\ActivityEntry;

final readonly class ShowWorkshopView
{
    /**
     * @param list<WorkshopAttentionItem> $attention
     * @param list<WorkshopCard>          $cards
     * @param list<ActivityEntry>         $activity
     */
    public function __construct(
        public Project $project,
        public ProjectStats $stats,
        public array $attention,
        public array $cards,
        public array $activity,
        /** @var list<WorkshopConnection> */
        public array $connections,
    ) {
    }
}
