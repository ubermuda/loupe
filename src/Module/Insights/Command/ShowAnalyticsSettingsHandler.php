<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Repository\InsightsProjectSettingsRepository;
use App\Module\Insights\Service\AnalysisSettings;

final readonly class ShowAnalyticsSettingsHandler
{
    public function __construct(
        private InsightsProjectSettingsRepository $insightsProjectSettings,
        private AnalysisSettings $settings,
    ) {
    }

    public function __invoke(ShowAnalyticsSettingsCommand $command): AnalyticsSettingsView
    {
        $project = $command->project;
        $own = $this->insightsProjectSettings->findForProject($project);

        return new AnalyticsSettingsView(
            project: $project,
            defaultModel: $own?->defaultModel,
            defaultEffort: $own?->defaultEffort,
            collectFullText: $own->collectFullText ?? false,
            subcommandPrograms: $own?->subcommandPrograms,
            model: $this->settings->modelFor($project),
            effort: $this->settings->effortFor($project),
        );
    }
}
