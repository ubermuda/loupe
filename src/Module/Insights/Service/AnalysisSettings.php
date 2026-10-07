<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Service\ProjectCollectionSettingsInterface;
use App\Module\Insights\Repository\InsightsProjectSettingsRepository;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** The model, the effort and the collection setting of a project: its own value, else the instance flag, else the coded default. */
#[AsAlias(ProjectCollectionSettingsInterface::class)]
final readonly class AnalysisSettings implements ProjectCollectionSettingsInterface
{
    public const string MODEL_FLAG = 'insights.default_analysis_model';

    public const string EFFORT_FLAG = 'insights.default_analysis_effort';

    public const string DEFAULT_MODEL = 'sonnet';

    public const string DEFAULT_EFFORT = 'medium';

    public function __construct(
        private InsightsProjectSettingsRepository $insightsProjectSettings,
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function modelFor(Project $project): string
    {
        $model = $this->insightsProjectSettings->findForProject($project)->defaultModel
            ?? $this->featureFlags->getStringValue(self::MODEL_FLAG, self::DEFAULT_MODEL);

        return 1 === preg_match(WorkRequest::MODEL_PATTERN, $model) ? $model : self::DEFAULT_MODEL;
    }

    public function effortFor(Project $project): string
    {
        $effort = $this->insightsProjectSettings->findForProject($project)->defaultEffort
            ?? $this->featureFlags->getStringValue(self::EFFORT_FLAG, self::DEFAULT_EFFORT);

        return \in_array($effort, WorkRequest::EFFORTS, true) ? $effort : self::DEFAULT_EFFORT;
    }

    #[\Override]
    public function collectFullText(Project $project): bool
    {
        return $this->insightsProjectSettings->findForProject($project)->collectFullText ?? false;
    }
}
