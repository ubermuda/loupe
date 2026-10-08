<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Project\Entity\Project;

final readonly class ListReportsView
{
    /** @param list<AnalysisDetailView> $analyses newest first */
    public function __construct(
        public Project $project,
        public array $analyses,
        public AnalyticsSettingsView $settings,
        public int $page = 1,
        public int $totalPages = 1,
        /** @var list<int|null> */
        public array $pageList = [1],
    ) {
    }
}
