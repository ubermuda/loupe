<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;
use Doctrine\ORM\Tools\Pagination\Paginator;

final readonly class ListOutboxView
{
    /**
     * @param Paginator<OutboxEvent> $events
     * @param list<Project>          $projects
     * @param list<int|null>         $pageList
     * @param array<string, mixed>   $filters
     */
    public function __construct(
        public Paginator $events,
        public array $projects,
        public string $selectedProjectId,
        public int $total,
        public int $totalPages,
        public array $pageList,
        public array $filters,
        public ?int $clampedPage,
    ) {
    }
}
