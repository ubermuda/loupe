<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Exception\DomainErrors;
use App\Module\Insights\Command\ReportAnalysisCommand;
use App\Module\Insights\Command\ReportAnalysisHandler;
use App\Module\Insights\Command\ReportedProposal;
use App\Module\Insights\Command\ShowAnalysisCommand;
use App\Module\Insights\Command\ShowAnalysisHandler;
use App\Module\Insights\Entity\Proposal;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Finishes an analysis with its report document and its proposals.
 *
 * @phpstan-import-type AnalysisPayloadShape from AnalysisPayload
 */
#[McpTool(name: self::NAME, description: 'Finish an analysis of this project with its report. First write the report with document_create, then pass its id as documentId. Pass analysisId, the subject id of your analysis work request. proposals lists at most 20 changes you propose, in the order the owner reads them, and an empty list is valid. Each proposal has kind, title and body. kind is card for a change that becomes a backlog card when the owner accepts it, or bucket-rule for a rule that groups runs. title is one line of at most 200 characters. body is the detail, in Markdown. payload is an optional object with the data a bucket-rule needs. estimatedSaving is an optional short text of at most 200 characters, such as "about $4 a week". The analysis then reads done, and the owner accepts or dismisses each proposal on the Reports page. A done or failed analysis takes no second report. The response is the analysis, as analysis_get answers it.')]
final readonly class AnalysisReportTool
{
    public const string NAME = 'analysis_report';

    public function __construct(
        private InsightsSubjectResolver $subjects,
        private ReportAnalysisHandler $reportAnalysis,
        private ShowAnalysisHandler $showAnalysis,
        private InsightsToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string       $analysisId the id of the analysis, the subject id of your work request
     * @param string       $documentId the id of the report document, from document_create
     * @param array<mixed> $proposals  the changes you propose
     *
     * @return AnalysisPayloadShape
     */
    public function __invoke(
        string $analysisId,
        string $documentId,
        #[Schema(type: 'array', items: [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['card', 'bucket-rule']],
                'title' => ['type' => 'string', 'maxLength' => Proposal::MAX_TITLE_LENGTH, 'description' => 'one line'],
                'body' => ['type' => 'string', 'description' => 'the detail, in Markdown'],
                'payload' => ['type' => 'object', 'description' => 'the data a bucket-rule needs'],
                'estimatedSaving' => ['type' => 'string', 'maxLength' => Proposal::MAX_ESTIMATED_SAVING_LENGTH, 'description' => 'a short text, such as "about $4 a week"'],
            ],
            'required' => ['kind', 'title', 'body'],
        ], maxItems: ReportAnalysisHandler::MAX_PROPOSALS)]
        array $proposals = [],
    ): array {
        try {
            $project = $this->subjects->requireWritableProject();
            $command = new ReportAnalysisCommand($project, $analysisId, $documentId, self::proposals($proposals));

            try {
                $analysis = ($this->reportAnalysis)($command);
            } catch (DomainErrors $e) {
                throw $this->errorMessages->forAgent($e);
            }

            return AnalysisPayload::of(($this->showAnalysis)(new ShowAnalysisCommand($project, (string) $analysis->id)));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The report could not be saved. The error has been logged.', previous: $e);
        }
    }

    /**
     * The handler checks the values, so this checks the shape alone.
     *
     * @param array<mixed> $proposals
     *
     * @return list<ReportedProposal>
     */
    private static function proposals(array $proposals): array
    {
        $parsed = [];
        foreach (array_values($proposals) as $index => $proposal) {
            if (!\is_array($proposal)) {
                throw new ToolCallException(\sprintf('proposals[%d] must be an object.', $index));
            }
            $payload = $proposal['payload'] ?? null;
            if (null !== $payload && !\is_array($payload)) {
                throw new ToolCallException(\sprintf('proposals[%d].payload must be an object.', $index));
            }
            $parsed[] = new ReportedProposal(
                kind: self::string($proposal, 'kind', $index),
                title: self::string($proposal, 'title', $index),
                body: self::string($proposal, 'body', $index),
                payload: $payload,
                estimatedSaving: null === ($proposal['estimatedSaving'] ?? null) ? null : self::string($proposal, 'estimatedSaving', $index),
            );
        }

        return $parsed;
    }

    /** @param array<mixed> $proposal */
    private static function string(array $proposal, string $key, int $index): string
    {
        $value = $proposal[$key] ?? null;

        return \is_string($value) ? $value : throw new ToolCallException(\sprintf('proposals[%d].%s must be a string.', $index, $key));
    }
}
