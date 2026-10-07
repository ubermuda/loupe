<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Repository\ProposalRepository;

final readonly class ListReportsHandler
{
    public function __construct(
        private AnalysisRepository $analyses,
        private ProposalRepository $proposals,
        private ShowAnalyticsSettingsHandler $showSettings,
    ) {
    }

    public function __invoke(ListReportsCommand $command): ListReportsView
    {
        return new ListReportsView(
            project: $command->project,
            analyses: array_map(
                fn (Analysis $analysis): AnalysisDetailView => new AnalysisDetailView($analysis, $this->analyses->costOf($analysis), $this->proposals->findByAnalysis($analysis)),
                $this->analyses->findByProject($command->project),
            ),
            settings: ($this->showSettings)(new ShowAnalyticsSettingsCommand($command->project)),
        );
    }
}
