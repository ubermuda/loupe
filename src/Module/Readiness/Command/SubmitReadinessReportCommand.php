<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Project\Entity\Project;

final readonly class SubmitReadinessReportCommand
{
    /**
     * @param list<ReportFinding>  $findings
     * @param list<ReportProposal> $proposals
     */
    public function __construct(
        public Project $project,
        public string $runId,
        public string $workflow,
        public array $findings,
        public array $proposals = [],
        public string $summary = '',
    ) {
    }
}
