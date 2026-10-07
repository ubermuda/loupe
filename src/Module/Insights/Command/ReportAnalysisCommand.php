<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Project\Entity\Project;

final readonly class ReportAnalysisCommand
{
    /** @param list<ReportedProposal> $proposals */
    public function __construct(
        public Project $project,
        public string $analysisId,
        /** A document of the same project. */
        public string $documentId,
        public array $proposals,
    ) {
    }
}
