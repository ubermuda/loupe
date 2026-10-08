<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\View\MetricsQuery;
use App\Module\Project\Entity\Project;

final readonly class ShowMetricsCommand
{
    public function __construct(
        public Project $project,
        public MetricsQuery $query,
    ) {
    }
}
