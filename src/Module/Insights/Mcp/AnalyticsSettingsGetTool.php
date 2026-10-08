<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Module\Insights\Command\AnalyticsSettingsView;
use App\Module\Insights\Command\ShowAnalyticsSettingsCommand;
use App\Module\Insights\Command\ShowAnalyticsSettingsHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the analysis settings of the project.
 *
 * @phpstan-type AnalyticsSettingsPayload array{defaultModel: ?string, defaultEffort: ?string, collectFullText: bool, subcommandPrograms: list<string>, model: string, effort: string}
 */
#[McpTool(name: self::NAME, description: 'Read the analysis settings of this project. defaultModel and defaultEffort are the values the project sets, or null when it sets none. model and effort are the values a new analysis takes when it names none: the project value, else the instance default. effort is low, medium, high, xhigh or max. collectFullText says whether the bridge sends the full text of each tool call. subcommandPrograms is the list of programs whose second word joins the signature of a shell command, such as git or npm, as this project sets it. It is empty when the project sets none, so the instance list applies. Change the settings with analytics_settings_update.')]
final readonly class AnalyticsSettingsGetTool
{
    public const string NAME = 'analytics_settings_get';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private ShowAnalyticsSettingsHandler $showSettings,
    ) {
    }

    /** @return AnalyticsSettingsPayload */
    public function __invoke(): array
    {
        try {
            return self::payload(($this->showSettings)(new ShowAnalyticsSettingsCommand($this->subjects->requireReadableProject())));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The analysis settings could not be read. The error has been logged.', previous: $e);
        }
    }

    /** @return AnalyticsSettingsPayload */
    public static function payload(AnalyticsSettingsView $view): array
    {
        return [
            'defaultModel' => $view->defaultModel,
            'defaultEffort' => $view->defaultEffort,
            'collectFullText' => $view->collectFullText,
            'subcommandPrograms' => $view->subcommandPrograms ?? [],
            'model' => $view->model,
            'effort' => $view->effort,
        ];
    }
}
