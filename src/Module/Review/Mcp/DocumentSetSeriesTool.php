<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Command\SetDocumentSeriesCommand;
use App\Module\Review\Command\SetDocumentSeriesHandler;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Moves a document to one place in one series, or takes it out of the series it
 * was in.
 */
#[McpTool(name: 'document_set_series', description: 'Place a document at a numbered position in an ordered set, such as post 5 of a blog series. Omit both series and seriesOrdinal to take the document out of the series it is in. A series name is stored as you spell it, matched ignoring case, and created on first use. No two documents in one series may hold the same number.')]
final readonly class DocumentSetSeriesTool
{
    public function __construct(
        private SetDocumentSeriesHandler $setSeries,
        private ReviewSubjectResolver $subjects,
        private ToolCallErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string      $documentId    The UUID of the document to place
     * @param string|null $series        Name of the ordered set the document belongs to; omit to take it out of its series
     * @param int|null    $seriesOrdinal Position of the document in that series, counting from 1; required whenever series is given
     *
     * @return array{documentId: string, series: ?string, seriesOrdinal: ?int}
     */
    public function __invoke(string $documentId, ?string $series = null, ?int $seriesOrdinal = null): array
    {
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

            $applied = ($this->setSeries)(new SetDocumentSeriesCommand($document, $series, $seriesOrdinal));

            return [
                'documentId' => (string) $document->id,
                'series' => $applied?->name,
                'seriesOrdinal' => $document->seriesOrdinal,
            ];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document series could not be set. The error has been logged.', previous: $e);
        }
    }
}
