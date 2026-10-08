<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Repository\ProposalRepository;
use App\Utils\PageList;

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
        $totalPages = max(1, (int) ceil($this->analyses->countByProject($command->project) / self::LIMIT));
        $page = min(max(1, $command->page), $totalPages);
        $analyses = $this->analyses->findByProject($command->project, self::LIMIT, ($page - 1) * self::LIMIT);
        $costs = $this->analyses->costsOf($command->project, $analyses);
        $proposals = $this->proposals->findByAnalyses($analyses);

        return new ListReportsView(
            project: $command->project,
            analyses: array_map(
                static fn (Analysis $analysis): AnalysisDetailView => new AnalysisDetailView($analysis, $costs[(string) $analysis->id] ?? null, $proposals[(string) $analysis->id] ?? []),
                $analyses,
            ),
            settings: ($this->showSettings)(new ShowAnalyticsSettingsCommand($command->project)),
            page: $page,
            totalPages: $totalPages,
            pageList: PageList::build($page, $totalPages),
        );
    }
}
