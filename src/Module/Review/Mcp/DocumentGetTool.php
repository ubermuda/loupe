<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Module\Review\Command\ShowDocumentDataCommand;
use App\Module\Review\Command\ShowDocumentDataHandler;
use App\Module\Review\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Fetch a document's current Markdown source and status by id.
 *
 * @phpstan-import-type DocumentPayload from ShowDocumentDataHandler
 */
#[McpTool(name: 'document_get', description: 'Fetch a document\'s current Markdown source, title, status, archive state and reason, version number, that version\'s description, its tags, its place in a series, and the documents it references.')]
final readonly class DocumentGetTool
{
    public function __construct(
        private ShowDocumentDataHandler $showDocumentData,
        private ReviewSubjectResolver $subjects,
    ) {
    }

    /**
     * @param string $documentId The UUID of the document to retrieve
     *
     * @return DocumentPayload
     */
    public function __invoke(string $documentId): array
    {
        // Resolution is inside the try because it queries the database, and an
        // unwrapped failure there reaches the client as an internal error with
        // no detail. The re-throw guard keeps the messages it raises on
        // purpose — including the deliberately identical authorization ones —
        // from being flattened by the catch-all below.
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_READ);

            return ($this->showDocumentData)(new ShowDocumentDataCommand($document));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document could not be read. The error has been logged.', previous: $e);
        }
    }
}
