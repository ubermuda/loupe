<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Repository\DocumentRepository;
use Mcp\Capability\Attribute\McpTool;

/**
 * List all documents in the project bound to the authenticated MCP token.
 */
#[McpTool(name: 'list_documents', description: 'List all documents in the token\'s project, with their current status and version.')]
final readonly class ListDocumentsTool
{
    use ResolvesBoundProject;

    public function __construct(
        private DocumentRepository $documents,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * The list is wrapped in a `documents` object key because the MCP spec requires
     * a tool result's `structuredContent` to be a JSON object, not a bare array.
     *
     * @return array{documents: list<array{documentId: string, title: string, status: string, currentVersion: int}>}
     */
    public function __invoke(): array
    {
        $project = $this->requireBoundProject($this->projectResolver);

        $documents = $this->documents->findByProject($project);

        return [
            'documents' => array_map(
                static fn ($doc) => [
                    'documentId' => (string) $doc->id,
                    'title' => $doc->title,
                    'status' => $doc->status->value,
                    'currentVersion' => $doc->currentVersion()->versionNumber,
                ],
                $documents,
            ),
        ];
    }
}
