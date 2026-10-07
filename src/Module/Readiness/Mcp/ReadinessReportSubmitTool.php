<?php

declare(strict_types=1);

namespace App\Module\Readiness\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Readiness\Command\ReportFinding;
use App\Module\Readiness\Command\ReportProposal;
use App\Module\Readiness\Command\SubmitReadinessReportCommand;
use App\Module\Readiness\Command\SubmitReadinessReportHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[McpTool(name: self::NAME, description: 'Submit the readiness report of a discovery run. Loupe writes the report as a document for the owner to review, and links it to the discovery card. runId is the run that discovery_start or readiness_get named. workflow names the workflow the project uses. findings lists the checks you made, each with a check name, a status of ready or gap, and the evidence you found. proposals lists cards that would close a gap, each with a key that is unique in the report, a title, a type (feature, bug, security, tooling, docs or idea) and a body. When an open card of this project covers a proposal already, pass its number as openCardNumber, and the report shows that card instead of a tick box. The owner ticks the proposals to keep and approves the report, and Loupe then creates one card in Next for each ticked proposal. One report is allowed for each run, and only while the run waits for it.')]
final readonly class ReadinessReportSubmitTool
{
    use ResolvesBoundProject;

    public const string NAME = 'readiness_report_submit';

    private const array FINDING_ITEM = [
        'type' => 'object',
        'properties' => [
            'check' => ['type' => 'string', 'description' => 'the name of the check'],
            'status' => ['type' => 'string', 'enum' => ['ready', 'gap'], 'description' => 'ready when the project meets the check, gap when it does not'],
            'evidence' => ['type' => 'string', 'description' => 'what you found, as short Markdown'],
        ],
        'required' => ['check', 'status', 'evidence'],
    ];

    private const array PROPOSAL_ITEM = [
        'type' => 'object',
        'properties' => [
            'key' => ['type' => 'string', 'description' => 'a short id that is unique in this report'],
            'title' => ['type' => 'string', 'description' => 'the title of the card to create'],
            'type' => ['type' => 'string', 'enum' => ['feature', 'bug', 'security', 'tooling', 'docs', 'idea'], 'description' => 'the card type'],
            'body' => ['type' => 'string', 'description' => 'what the card asks for, in Markdown'],
            'openCardNumber' => ['type' => 'integer', 'description' => 'the number of an open card of this project that covers the proposal already'],
        ],
        'required' => ['key', 'title', 'type', 'body'],
    ];

    public function __construct(
        private SubmitReadinessReportHandler $submitReport,
        private AuthenticatedProjectResolver $projectResolver,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * `array<mixed>` because the SDK reads the item shape from the Schema attribute.
     *
     * @param string       $runId     the id of the discovery run
     * @param string       $workflow  the name of the workflow the project uses
     * @param array<mixed> $findings  the checks, each with check, status and evidence
     * @param array<mixed> $proposals the cards to propose, each with key, title, type, body and optionally openCardNumber
     * @param string       $summary   a short Markdown summary of the report
     *
     * @return array{documentId: string, url: string}
     */
    public function __invoke(
        string $runId,
        string $workflow,
        #[Schema(items: self::FINDING_ITEM)]
        array $findings,
        #[Schema(items: self::PROPOSAL_ITEM)]
        array $proposals = [],
        string $summary = '',
    ): array {
        try {
            $project = $this->requireBoundProject($this->projectResolver);

            $document = ($this->submitReport)(new SubmitReadinessReportCommand(
                project: $project,
                runId: $runId,
                workflow: $workflow,
                findings: array_map(self::finding(...), array_values($findings)),
                proposals: array_map(self::proposal(...), array_values($proposals)),
                summary: $summary,
            ));

            return [
                'documentId' => (string) $document->id,
                'url' => $this->urls->generate('app_document_review', [
                    'projectId' => (string) $project->id,
                    'documentId' => (string) $document->id,
                ], UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        } catch (DomainErrors $e) {
            $message = match (array_first($e->errors)) {
                SubmitReadinessReportHandler::RUN_UNKNOWN => 'No discovery run of this project has that runId. Call readiness_get or discovery_start for the runId, then call readiness_report_submit again.',
                SubmitReadinessReportHandler::RUN_NOT_REQUESTED => 'This run does not wait for a report now. It failed, or it reported already. Call readiness_get to read its state, or discovery_start to open a new run.',
                SubmitReadinessReportHandler::ALREADY_REPORTED => 'This run has a report already. Call readiness_get to read it, or discovery_start to open a new run.',
                SubmitReadinessReportHandler::FINDING_INVALID => 'Each finding needs a check, an evidence text, and a status of ready or gap. Fix the findings, then call readiness_report_submit again.',
                SubmitReadinessReportHandler::PROPOSAL_INVALID => 'Each proposal needs a key of up to 64 characters, a title of up to 255 characters, and a type. Fix the proposals, then call readiness_report_submit again.',
                SubmitReadinessReportHandler::PROPOSAL_TYPE => 'A proposal type must be one of feature, bug, security, tooling, docs or idea. Fix the type, then call readiness_report_submit again.',
                SubmitReadinessReportHandler::PROPOSAL_DUPLICATE => 'Two proposals have the same key. Give each proposal its own key, then call readiness_report_submit again.',
                SubmitReadinessReportHandler::CARD_NOT_OPEN => 'An openCardNumber names a card that does not exist in this project, or a card that is finished. Use card_search for an open card, or leave openCardNumber out, then call readiness_report_submit again.',
                SubmitReadinessReportHandler::MARKUP_NOT_ALLOWED => 'The report text holds a decision block. Remove the "<!-- decision" comment from the text, then call readiness_report_submit again.',
                default => 'The request was rejected. The error has been logged.',
            };
            throw new ToolCallException($message, previous: $e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The report could not be submitted. The error has been logged.', previous: $e);
        }
    }

    private static function finding(mixed $item): ReportFinding
    {
        if (!\is_array($item) || !\is_string($item['check'] ?? null) || !\is_string($item['status'] ?? null) || !\is_string($item['evidence'] ?? null)) {
            throw new ToolCallException('Each finding needs a check, a status and an evidence text, all as strings. Fix the findings, then call readiness_report_submit again.');
        }

        return new ReportFinding($item['check'], $item['status'], $item['evidence']);
    }

    private static function proposal(mixed $item): ReportProposal
    {
        $number = \is_array($item) ? ($item['openCardNumber'] ?? null) : null;
        if (!\is_array($item) || !\is_string($item['key'] ?? null) || !\is_string($item['title'] ?? null) || !\is_string($item['type'] ?? null) || !\is_string($item['body'] ?? null)
            || (null !== $number && !\is_int($number))) {
            throw new ToolCallException('Each proposal needs a key, a title, a type and a body as strings, and an openCardNumber as an integer when it has one. Fix the proposals, then call readiness_report_submit again.');
        }

        return new ReportProposal($item['key'], $item['title'], $item['type'], $item['body'], $number);
    }
}
