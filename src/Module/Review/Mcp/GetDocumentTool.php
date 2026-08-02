<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Query\GetDocument;
use App\Module\Review\Repository\DocumentRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Fetch a document's current Markdown source and status by id.
 */
#[McpTool(name: 'document_get', description: 'Fetch a document\'s current Markdown source, title, status, and version number.')]
final readonly class GetDocumentTool
{
    use ResolvesBoundProject;

    public function __construct(
        private GetDocument $getDocument,
        private AuthenticatedProjectResolver $projectResolver,
        private DocumentRepository $documents,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * @param string $documentId The UUID of the document to retrieve
     *
     * @return array{documentId: string, title: string, status: string, version: int, markdown: string}
     */
    public function __invoke(string $documentId): array
    {
        $document = $this->requireDocument($documentId, $this->projectResolver, $this->documents, $this->authorization);

        try {
            return ($this->getDocument)($document);
        } catch (\Throwable $e) {
            throw new ToolCallException('The document could not be read. The error has been logged.', previous: $e);
        }
    }
}
