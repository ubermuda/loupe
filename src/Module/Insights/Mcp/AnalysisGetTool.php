<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Exception\DomainErrors;
use App\Module\Insights\Command\ShowAnalysisCommand;
use App\Module\Insights\Command\ShowAnalysisHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads one analysis of the project, with its cost and its proposals.
 *
 * @phpstan-import-type AnalysisPayloadShape from AnalysisPayload
 */
#[McpTool(name: self::NAME, description: 'Read one analysis of this project. Pass analysisId, the subject id of your analysis work request. The response has id, topic (cost, time, experiment, host or question), scope with range (thirty-days, ninety-days or all: the runs the analysis reads) and, for the experiment topic, experiment (the name of the experiment it compares), question (null unless the topic is question), model, effort, state (waiting, running, done, failed or paused), reason (why it failed or paused, else null), createdAt, finishedAt, documentId (the report document once done, else null), costUsd (the cost in US dollars of the runs of this analysis so far, null when no run has a known cost) and proposals. Each proposal has id, kind (card or bucket-rule), title, body, payload, estimatedSaving, state (proposed, created or dismissed), dismissReason and cardId (the card it created). Read the runs with metric_query, then write the report with document_create and finish with analysis_report.')]
final readonly class AnalysisGetTool
{
    public const string NAME = 'analysis_get';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private ShowAnalysisHandler $showAnalysis,
    ) {
    }

    /**
     * @param string $analysisId the id of the analysis, the subject id of your work request
     *
     * @return AnalysisPayloadShape
     */
    public function __invoke(string $analysisId): array
    {
        try {
            $project = $this->subjects->requireReadableProject();

            try {
                $view = ($this->showAnalysis)(new ShowAnalysisCommand($project, $analysisId));
            } catch (DomainErrors $e) {
                throw new ToolCallException(self::notFound($analysisId), previous: $e);
            }

            return AnalysisPayload::of($view);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The analysis could not be read. The error has been logged.', previous: $e);
        }
    }

    private static function notFound(string $analysisId): string
    {
        return \sprintf('Analysis "%s" not found in this project. Pass the analysis id from your work request.', mb_substr($analysisId, 0, 64));
    }
}
