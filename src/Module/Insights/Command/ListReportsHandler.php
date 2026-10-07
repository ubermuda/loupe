<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Repository\ProposalRepository;

final readonly class ListReportsHandler
{
    public const int LIMIT = 50;

    public function __construct(
        private AnalysisRepository $analyses,
        private ProposalRepository $proposals,
        private ShowAnalyticsSettingsHandler $showSettings,
    ) {
    }

    public function __invoke(ListReportsCommand $command): ListReportsView
    {
        $analyses = $this->analyses->findByProject($command->project, self::LIMIT + 1);
        $more = \count($analyses) > self::LIMIT;
        $analyses = \array_slice($analyses, 0, self::LIMIT);
        $costs = $this->analyses->costsOf($command->project, $analyses);
        $proposals = $this->proposals->findByAnalyses($analyses);

        return new ListReportsView(
            project: $command->project,
            analyses: array_map(
                static fn (Analysis $analysis): AnalysisDetailView => new AnalysisDetailView($analysis, $costs[(string) $analysis->id] ?? null, $proposals[(string) $analysis->id] ?? []),
                $analyses,
            ),
            settings: ($this->showSettings)(new ShowAnalyticsSettingsCommand($command->project)),
            more: $more,
        );
    }
}
