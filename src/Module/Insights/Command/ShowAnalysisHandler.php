<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Repository\ProposalRepository;

final readonly class ShowAnalysisHandler
{
    public function __construct(
        private AnalysisRepository $analyses,
        private ProposalRepository $proposals,
    ) {
    }

    public function __invoke(ShowAnalysisCommand $command): AnalysisDetailView
    {
        $analysis = $this->analyses->findOneByIdAndProject($command->analysisId, $command->project)
            ?? throw new DomainErrors(['analysisId' => ReportAnalysisHandler::UNKNOWN_ANALYSIS]);

        return new AnalysisDetailView($analysis, $this->analyses->costOf($analysis), $this->proposals->findByAnalysis($analysis));
    }
}
