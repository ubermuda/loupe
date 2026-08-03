<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Module\Review\Query\GetDocument;
use App\Module\Review\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Fetch a document's current Markdown source and status by id.
 */
#[McpTool(name: 'document_get', description: 'Fetch a document\'s current Markdown source, title, status, archive state, version number, and that version\'s description.')]
final readonly class DocumentGetTool
{
    public function __construct(
        private GetDocument $getDocument,
        private ReviewSubjectResolver $subjects,
    ) {
    }

    /**
     * @param string $documentId The UUID of the document to retrieve
     *
     * @return array{documentId: string, title: string, status: string, archived: bool, version: int, versionDescription: ?string, markdown: string}
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

            return ($this->getDocument)($document);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document could not be read. The error has been logged.', previous: $e);
        }
    }
}
