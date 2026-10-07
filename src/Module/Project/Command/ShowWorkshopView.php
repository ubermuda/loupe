<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Entity\Project;
use App\Module\Project\Stats\ProjectStats;
use App\Module\Project\Workshop\WorkshopAttentionItem;
use App\Module\Project\Workshop\WorkshopCardsInMotion;
use App\Module\Project\Workshop\WorkshopConnection;
use App\Module\Project\Workshop\WorkshopReadiness;
use App\Outbox\ActivityEntry;

final readonly class ShowWorkshopView
{
    /**
     * @param list<WorkshopAttentionItem> $attention
     * @param list<ActivityEntry>         $activity
     */
    public function __construct(
        public Project $project,
        public ProjectStats $stats,
        public array $attention,
        public WorkshopCardsInMotion $inMotion,
        public array $activity,
        /** @var list<WorkshopConnection> */
        public array $connections,
        /** Null once the owner hides the guide. */
        public ?WorkshopReadiness $readiness,
        /** Nobody waits on the owner and no card is in motion. */
        public bool $quiet,
    ) {
    }
}
