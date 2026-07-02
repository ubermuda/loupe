<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Repository\DocumentRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * List all documents in the project bound to the authenticated MCP token.
 */
#[McpTool(name: 'list_documents', description: 'List all documents in the token\'s project, with their current status and version.')]
final readonly class ListDocumentsTool
{
    public function __construct(
        private DocumentRepository $documents,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * @return list<array{documentId: string, title: string, status: string, currentVersion: int}>
     */
    public function __invoke(): array
    {
        $project = $this->projectResolver->resolveMcpProject();
        if (null === $project) {
            throw new ToolCallException('MCP token is not bound to a project. Mint a project token from the Connect page.');
        }

        $documents = $this->documents->findByProject($project);

        return array_map(
            static fn ($doc) => [
                'documentId' => (string) $doc->id,
                'title' => $doc->title,
                'status' => $doc->status->value,
                'currentVersion' => $doc->currentVersion()->versionNumber,
            ],
            $documents,
        );
    }
}
