<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Query\DocumentNotFound;
use App\Module\Review\Query\GetDocument;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Uid\Uuid;

/**
 * Fetch a document's current Markdown source and status by id.
 */
#[McpTool(name: 'get_document', description: 'Fetch a document\'s current Markdown source, title, status, and version number.')]
final readonly class GetDocumentTool
{
    use ResolvesBoundProject;

    public function __construct(
        private GetDocument $getDocument,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * @param string $documentId The UUID of the document to retrieve
     *
     * @return array{documentId: string, title: string, status: string, version: int, markdown: string}
     */
    public function __invoke(string $documentId): array
    {
        $project = $this->requireBoundProject($this->projectResolver);

        try {
            return ($this->getDocument)(Uuid::fromString($documentId), $project);
        } catch (DocumentNotFound $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException(\sprintf('"%s" is not a valid document ID.', $documentId), previous: $e);
        }
    }
}
