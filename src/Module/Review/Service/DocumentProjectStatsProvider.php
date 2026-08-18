<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Project\Stats\ProjectStats;
use App\Module\Project\Stats\ProjectStatsProviderInterface;
use App\Module\Review\Repository\DocumentRepository;

/** Review's contribution to the projects list: how many active documents each has. */
final readonly class DocumentProjectStatsProvider implements ProjectStatsProviderInterface
{
    public function __construct(
        private DocumentRepository $documents,
    ) {
    }

    #[\Override]
    public function statsFor(array $projects): array
    {
        return array_map(
            static fn (int $count): ProjectStats => new ProjectStats(documentCount: $count),
            $this->documents->countActiveByProjects($projects),
        );
    }
}
