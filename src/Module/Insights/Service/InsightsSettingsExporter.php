<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Insights\Repository\InsightsProjectSettingsRepository;

/** The analysis settings of the projects the user owns. */
final readonly class InsightsSettingsExporter implements UserDataExporterInterface
{
    public function __construct(
        private InsightsProjectSettingsRepository $insightsProjectSettings,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'insights_project_settings.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->insightsProjectSettings->findByOwner($user) as $settings) {
            yield [
                'projectId' => (string) $settings->project->id,
                'project' => $settings->project->name,
                'defaultModel' => $settings->defaultModel,
                'defaultEffort' => $settings->defaultEffort,
                'collectFullText' => $settings->collectFullText,
                'subcommandPrograms' => $settings->subcommandPrograms,
            ];
        }
    }
}
