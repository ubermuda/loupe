<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Create a Markdown document for human review and return its id and review URL.
 */
#[McpTool(name: 'create_document', description: 'Create a Markdown document for human review.')]
final readonly class CreateDocumentTool
{
    public function __construct(
        private CreateDocumentHandler $createDocument,
        private AuthenticatedProjectResolver $projectResolver,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param string $title    The document title
     * @param string $markdown The document content in Markdown format
     *
     * @return array{documentId: string, reviewUrl: string}
     */
    public function __invoke(string $title, string $markdown): array
    {
        $project = $this->projectResolver->resolveMcpProject();
        if (null === $project) {
            throw new ToolCallException('MCP token is not bound to a project. Mint a project token from the Connect page.');
        }

        $doc = ($this->createDocument)(new CreateDocumentCommand($project, $title, $markdown));

        return [
            'documentId' => (string) $doc->id,
            // Route app_document_review is provided by the reviewer UI (Task 11); the tool
            // resolves it to an absolute URL the agent shares with the human reviewer.
            'reviewUrl' => $this->urls->generate('app_document_review', ['id' => $doc->id], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }
}
