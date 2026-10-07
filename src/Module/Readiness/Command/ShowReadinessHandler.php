<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Readiness\Service\ReadinessChecklist;

/** Reads every readiness check of a project, whether the Workshop guide shows or not. */
final readonly class ShowReadinessHandler
{
    public function __construct(
        private ReadinessChecklist $checklist,
        private DiscoveryRunRepository $discoveryRuns,
    ) {
    }

    public function __invoke(ShowReadinessCommand $command): ReadinessView
    {
        $project = $command->project;

        return new ReadinessView(
            $project,
            null !== $project->readinessGuideHiddenAt,
            $this->checklist->rows($project),
            $this->discoveryRuns->latestForProject($project),
        );
    }
}
