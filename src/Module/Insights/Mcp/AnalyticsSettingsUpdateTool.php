<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Exception\DomainErrors;
use App\Module\Insights\Command\ShowAnalyticsSettingsCommand;
use App\Module\Insights\Command\ShowAnalyticsSettingsHandler;
use App\Module\Insights\Command\UpdateAnalyticsSettingsCommand;
use App\Module\Insights\Command\UpdateAnalyticsSettingsHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Changes the analysis settings of the project.
 *
 * @phpstan-import-type AnalyticsSettingsPayload from AnalyticsSettingsGetTool
 */
#[McpTool(name: self::NAME, description: 'Change the analysis settings of this project. Pass only the values you change: an argument you leave out keeps its value. defaultModel is the model a new analysis takes when it names none, one word such as sonnet or opus. defaultEffort is low, medium, high, xhigh or max. Pass an empty string as defaultModel or defaultEffort to clear the project value, so the instance default applies. collectFullText says whether the bridge sends the full text of each tool call. The response is the settings, as analytics_settings_get answers them.')]
final readonly class AnalyticsSettingsUpdateTool
{
    public const string NAME = 'analytics_settings_update';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private ShowAnalyticsSettingsHandler $showSettings,
        private UpdateAnalyticsSettingsHandler $updateSettings,
        private InsightsToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string|null $defaultModel    the model, or an empty string to clear it
     * @param string|null $defaultEffort   low, medium, high, xhigh or max, or an empty string to clear it
     * @param bool|null   $collectFullText whether the bridge sends the full text of each tool call
     *
     * @return AnalyticsSettingsPayload
     */
    public function __invoke(?string $defaultModel = null, ?string $defaultEffort = null, ?bool $collectFullText = null): array
    {
        try {
            $project = $this->subjects->requireWritableProject();
            $current = ($this->showSettings)(new ShowAnalyticsSettingsCommand($project));

            try {
                ($this->updateSettings)(new UpdateAnalyticsSettingsCommand(
                    project: $project,
                    model: null === $defaultModel ? $current->defaultModel : ('' === $defaultModel ? null : $defaultModel),
                    effort: null === $defaultEffort ? $current->defaultEffort : ('' === $defaultEffort ? null : $defaultEffort),
                    collectFullText: $collectFullText ?? $current->collectFullText,
                ));
            } catch (DomainErrors $e) {
                throw $this->errorMessages->forAgent($e, ['model' => 'defaultModel', 'effort' => 'defaultEffort']);
            }

            return AnalyticsSettingsGetTool::payload(($this->showSettings)(new ShowAnalyticsSettingsCommand($project)));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The analysis settings could not be saved. The error has been logged.', previous: $e);
        }
    }
}
